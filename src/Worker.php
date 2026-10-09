<?php
declare(strict_types=1);
namespace Talixio;

/** The always-on loop: Telegram, scheduled tasks, heartbeat, background jobs, memory indexing. */
final class Worker
{
    private int $lastSummary = 0;
    private int $lastEmail = 0;
    private int $lastSignal = 0;

    public function __construct(private App $app) {}

    public function run(): never
    {
        $cfg = $this->app->cfg;
        Log::info("worker started: provider={$cfg->provider} tiers=" . implode(',', array_map(fn(Tier $t) => "{$t->name}:{$t->label()}", $cfg->tiers))
            . " search={$cfg->search} embeddings={$cfg->embeddings} telegram=" . ($cfg->telegramToken ? 'on' : 'off')
            . ' email=' . ($this->app->email()->canReceive() ? 'in+out' : ($this->app->email()->canSend() ? 'out' : 'off'))
            . ' signal=' . ($this->app->signal()->enabled() ? 'on' : 'off') . ' ntfy=' . ($cfg->ntfyUrl ? 'on' : 'off') . " heartbeat={$cfg->heartbeatMinutes}m budget=\${$cfg->budgetDaily}/day,\${$cfg->budgetMonthly}/month tz={$cfg->timezone}");
        App::$inWorker = true;
        $this->app->jobs()->recoverInterrupted();
        $this->app->files()->recoverInterrupted();
        $tg = $cfg->telegramToken !== '' ? new Telegram($cfg, $this->app) : null;
        $settingsFile = Settings::file($cfg->dataDir);
        $settingsAt = @filemtime($settingsFile) ?: 0;
        while (true) {
            clearstatcache(true, $settingsFile);
            if ((@filemtime($settingsFile) ?: 0) !== $settingsAt && Jobs::runningCount() === 0) {
                Log::info('settings changed in the web app: restarting the worker to apply them');
                exit(0); // systemd restarts it with the new settings
            }
            $this->app->store->set('worker_seen', (string)time());
            try {
                if ($tg) $tg->poll(Jobs::runningCount() > 0 ? 3 : 10); else sleep(3);
            } catch (\Throwable $e) {
                Log::error("telegram: {$e->getMessage()}");
                sleep(5);
            }
            $this->tick(time());
        }
    }

    public function tick(int $now): void
    {
        $app = $this->app;
        $store = $app->store;
        $this->guard('jobs', fn() => $app->jobs()->dispatch());
        foreach ($store->dueTasks($now) as $t) {
            // advance first so a crash never makes a task fire twice
            $store->updateTask((int)$t['id'], $t['cron']
                ? ['next_run' => Cron::next($t['cron'], $now), 'last_run' => $now]
                : ['enabled' => 0, 'next_run' => null, 'last_run' => $now]);
            $this->guard("task {$t['id']}", fn() => $app->notifier()->notify($t['title'], $app->agent()->runTask($t['title'], $t['prompt'], (string)($t['tier'] ?? 'standard'))));
        }
        $every = $app->cfg->heartbeatMinutes * 60;
        if ($every > 0 && $now - (int)$store->get('heartbeat_last', '0') >= $every && $app->ws->hasContent('HEARTBEAT.md')) {
            $store->set('heartbeat_last', (string)$now);
            $this->guard('heartbeat', function () use ($app) {
                $msg = $app->agent()->heartbeat();
                if ($msg !== null) $app->notifier()->notify($app->cfg->name, $msg);
            });
        }
        if ($now - $this->lastSummary >= 600) {
            $this->lastSummary = $now;
            $this->guard('summary', fn() => (new Summarizer($app))->maybeRun($now));
        }
        if ($now - $this->lastSignal >= 5 && $app->signal()->enabled()) {
            $this->lastSignal = $now;
            $this->guard('signal', fn() => $app->signal()->poll());
        }
        if ($now - $this->lastEmail >= 60 && $app->email()->canReceive()) {
            $this->lastEmail = $now;
            $this->guard('email', fn() => $app->email()->poll());
        }
        if (App::$inWorker) $this->guard('library', fn() => $app->files()->startIndexer());
        $this->guard('embeddings', fn() => $app->recall()->embedPending());
        if ($now % 600 < 15) $this->guard('update', fn() => $app->updater()->notifyIfNew());
        if ($app->backup()->due($now)) $this->guard('backup', fn() => Log::info('backup: ' . $app->backup()->run()));
    }

    private function guard(string $what, callable $fn): void
    {
        try {
            $fn();
            if (in_array($what, ['email', 'signal'], true)) $this->app->store->set("channel_error_$what", '');
        } catch (Abort $e) {
            Log::warn("$what: {$e->getMessage()}");
        } catch (\Throwable $e) {
            Log::error("$what: {$e->getMessage()}");
            if (in_array($what, ['email', 'signal'], true)) $this->app->store->set("channel_error_$what", date('Y-m-d H:i') . ' ' . $e->getMessage());
        }
    }
}
