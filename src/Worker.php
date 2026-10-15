<?php
declare(strict_types=1);
namespace Talixio;

/** The always-on loop: Telegram, scheduled tasks, heartbeat, background jobs, memory indexing. */
final class Worker
{
    private int $lastSummary = 0;
    private int $lastBatch = 0;
    private int $lastEmail = 0;
    private int $lastSignal = 0;
    private int $lastMcp = 0;
    private int $lastTriage = 0;

    public function __construct(private App $app) {}

    public function run(): never
    {
        $cfg = $this->app->cfg;
        Log::info('worker started: version ' . Version::get()['label'] . " provider={$cfg->provider} tiers=" . implode(',', array_map(fn(Tier $t) => "{$t->name}:{$t->label()}", $cfg->tiers))
            . " search={$cfg->search} embeddings={$cfg->embeddings} telegram=" . ($cfg->telegramToken ? 'on' : 'off')
            . ' email=' . ($this->app->email()->canReceive() ? 'in+out' : ($this->app->email()->canSend() ? 'out' : 'off'))
            . ' signal=' . ($this->app->signal()->enabled() ? 'on' : 'off') . ' ntfy=' . ($cfg->ntfyUrl ? 'on' : 'off') . " heartbeat={$cfg->heartbeatMinutes}m budget=\${$cfg->budgetDaily}/day,\${$cfg->budgetMonthly}/month tz={$cfg->timezone}");
        App::$inWorker = true;
        @unlink(Jobs::drainFile($cfg->dataDir)); // the update that asked for it is over
        $this->app->jobs()->recoverInterrupted();
        $this->app->files()->recoverInterrupted();
        try { (new Faq($this->app))->sync(); } catch (\Throwable $e) { Log::warn("faq: {$e->getMessage()}"); } // the notes about this version, in /FAQ
        if ($this->app->store->get('briefing_tasks') === '') { // once: the morning briefing also lists the tasks board
            $this->app->store->q("UPDATE tasks SET prompt = REPLACE(prompt, ?, ?) WHERE title = 'Morning briefing'", ["3. Reminders and deadlines today and in the next 3 days (list_tasks, memory).",
                "3. Tasks (board_list with all_spaces): overdue, due today and in the next 3 days, blocked ones; then the next routines and reminders (list_tasks)."]);
            $this->app->store->set('briefing_tasks', '1');
        }
        if ($this->app->store->get('fix.merge_duplicates') === '') { // once: a name used twice in a folder becomes one file with versions
            try { $this->app->library()->mergeDuplicates(); } catch (\Throwable $e) { Log::warn("merging duplicates: {$e->getMessage()}"); }
            $this->app->store->set('fix.merge_duplicates', '1');
        }
        if ($this->app->store->get('pages_indexed') === '') { // once: PDFs indexed by page, so answers can cite pages
            foreach ($this->app->store->q("SELECT id FROM files WHERE status = 'ready' AND deleted_at IS NULL AND (kind = 'pdf' OR (kind = 'office' AND name LIKE '%.pptx'))")->fetchAll() as $r) {
                try { $this->app->files()->index((int)$r['id']); } catch (\Throwable $e) { Log::warn("reindex {$r['id']}: {$e->getMessage()}"); }
            }
            $this->app->store->set('pages_indexed', '1');
        }
        $paused = Transfer::paused($this->app->store); // a copy on its way to another server, or the old server after a move
        if ($paused) Log::info("paused by a transfer ({$paused['reason']}, {$paused['url']}): no channels, no routines");
        $tg = $cfg->telegramToken !== '' && !$paused ? new Telegram($cfg, $this->app) : null;
        $settingsFile = Settings::file($cfg->dataDir);
        $settingsAt = @filemtime($settingsFile) ?: 0;
        while (true) {
            clearstatcache(true, $settingsFile);
            if ((@filemtime($settingsFile) ?: 0) !== $settingsAt && Jobs::runningCount() === 0) {
                Log::info('settings changed in the web app: restarting the worker to apply them');
                exit(0); // systemd restarts it with the new settings
            }
            $this->app->store->set('worker_seen', (string)time());
            if (($paused !== null) !== (Transfer::paused($this->app->store) !== null)) { Log::info('transfer pause changed: restarting the worker'); exit(0); } // channels on or off
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
        $paused = Transfer::paused($store) !== null; // only this server's housekeeping: nothing that answers, sends or acts
        foreach ($paused ? [] : $store->dueTasks($now) as $t) {
            // advance first so a crash never makes a task fire twice
            $store->updateTask((int)$t['id'], $t['cron']
                ? ['next_run' => Cron::next($t['cron'], $now), 'last_run' => $now]
                : ['enabled' => 0, 'next_run' => null, 'last_run' => $now]);
            if (!empty($t['job'])) { // a scheduled job: start it, its report comes when it is done
                try { $app->jobs()->runScheduled($t); }
                catch (\Throwable $e) { $this->guard("task {$t['id']}", fn() => $app->notifier()->notify($t['title'], I18n::t($app, 'The scheduled job could not start: {e}', ['e' => $e->getMessage()]), (int)($t['space'] ?? 0))); }
                continue;
            }
            $this->guard("task {$t['id']}", fn() => $app->notifier()->notify($t['title'], $app->agent()->runTask($t['title'], $t['prompt'], (string)($t['tier'] ?? 'standard'), (int)($t['space'] ?? 0), (int)$t['id']), (int)($t['space'] ?? 0)));
        }
        $every = $app->cfg->heartbeatMinutes * 60;
        if (!$paused && $every > 0 && $now - (int)$store->get('heartbeat_last', '0') >= $every && $app->ws->hasContent('HEARTBEAT.md')) {
            $store->set('heartbeat_last', (string)$now);
            $this->guard('heartbeat', function () use ($app) {
                $msg = $app->agent()->heartbeat();
                if ($msg !== null) $app->notifier()->notify($app->cfg->name, $msg);
            });
        }
        if (!$paused && $now - $this->lastSummary >= 600) {
            $this->lastSummary = $now;
            $this->guard('summary', fn() => (new Summarizer($app))->maybeRun($now));
            $this->guard('mind', fn() => $app->mind()->tick($now));
        }
        if ($now - $this->lastBatch >= 60) { // answers of the Mistral batches (summaries, the Mind)
            $this->lastBatch = $now;
            $this->guard('batch', fn() => (new Batch($app))->poll($now));
        }
        if (!$paused && $now - $this->lastSignal >= 5 && $app->signal()->enabled()) {
            $this->lastSignal = $now;
            $this->guard('signal', fn() => $app->signal()->poll());
        }
        if (!$paused && $now - $this->lastEmail >= 60 && $app->email()->canReceive()) {
            $this->lastEmail = $now;
            $this->guard('email', fn() => $app->email()->poll());
        }
        if (App::$inWorker) $this->guard('library', fn() => $app->files()->startIndexer());
        if (!$paused && $now - $this->lastTriage >= 900 && $app->triage()->enabled()) { // sort new emails every 15 minutes
            $this->lastTriage = $now;
            $this->guard('mail triage', fn() => $app->triage()->run());
        }
        if ($now - $this->lastMcp >= 6 * 3600 && $app->mcp()->servers()) { // connectors' tool lists
            $this->lastMcp = $now;
            $this->guard('connectors', fn() => $app->mcp()->refresh());
        }
        $this->guard('embeddings', fn() => $app->recall()->embedPending());
        if ($now % 600 < 15) $this->guard('update', fn() => $app->updater()->notifyIfNew());
        if ($app->backup()->due($now)) $this->guard('backup', fn() => Log::info('backup: ' . $app->backup()->run()));
        if ($now % 3600 < 15) $this->guard('tasks trash', fn() => $app->board()->purge());
        if ($now % 3600 < 15) $this->guard('jobs archive', fn() => $app->jobs()->autoArchive($now));
        if ($now % 3600 < 15) $this->guard('library trash', fn() => $app->library()->purgeTrash());
        if ($now % 86400 < 15) $this->guard('old shares', fn() => $app->shares()->purge());
        if ($now % 86400 < 15) $this->guard('privacy journal', fn() => $app->privacy()->purge());
        if ($paused) return;
        $this->guard('tasks calendar', fn() => $app->board()->syncGoogle());
        $this->guard('tasks reminder', fn() => $app->board()->remind($now));
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
