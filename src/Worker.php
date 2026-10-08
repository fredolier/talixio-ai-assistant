<?php
declare(strict_types=1);
namespace Talixio;

/** The always-on loop: Telegram, scheduled tasks, heartbeat. */
final class Worker
{
    public function __construct(private App $app) {}

    public function run(): never
    {
        $cfg = $this->app->cfg;
        Log::info("worker started: provider={$cfg->provider} search={$cfg->search} telegram=" . ($cfg->telegramToken ? 'on' : 'off')
            . " ntfy=" . ($cfg->ntfyUrl ? 'on' : 'off') . " heartbeat={$cfg->heartbeatMinutes}m tz={$cfg->timezone}");
        $tg = $cfg->telegramToken !== '' ? new Telegram($cfg, $this->app) : null;
        while (true) {
            $this->app->store->set('worker_seen', (string)time());
            try {
                if ($tg) $tg->poll(20); else sleep(15);
            } catch (\Throwable $e) {
                Log::error("telegram: {$e->getMessage()}");
                sleep(5);
            }
            $this->tick(time());
        }
    }

    public function tick(int $now): void
    {
        $store = $this->app->store;
        foreach ($store->dueTasks($now) as $t) {
            // advance first so a crash never makes a task fire twice
            $store->updateTask((int)$t['id'], $t['cron']
                ? ['next_run' => Cron::next($t['cron'], $now), 'last_run' => $now]
                : ['enabled' => 0, 'next_run' => null, 'last_run' => $now]);
            try {
                $reply = $this->app->agent()->runTask($t['title'], $t['prompt']);
                $this->app->notifier()->notify($t['title'], $reply);
            } catch (\Throwable $e) {
                Log::error("task {$t['id']}: {$e->getMessage()}");
            }
        }
        $every = $this->app->cfg->heartbeatMinutes * 60;
        if ($every > 0 && $now - (int)$store->get('heartbeat_last', '0') >= $every && $this->app->ws->hasContent('HEARTBEAT.md')) {
            $store->set('heartbeat_last', (string)$now);
            try {
                $msg = $this->app->agent($this->app->provider(cheap: true))->heartbeat();
                if ($msg !== null) $this->app->notifier()->notify($this->app->cfg->name, $msg);
            } catch (\Throwable $e) {
                Log::error("heartbeat: {$e->getMessage()}");
            }
        }
    }
}
