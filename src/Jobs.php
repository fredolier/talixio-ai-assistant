<?php
declare(strict_types=1);
namespace Talixio;

/** Background sub-agents. The worker starts each job as its own process (`talixio job ID`), up to TALIXIO_MAX_PARALLEL_JOBS at once. */
final class Jobs
{
    /** @var array<int,resource> job id => process, owned by the worker */
    private static array $procs = [];

    /** The configuration tune() changed and its tiers before. */
    private ?array $tuned = null;

    public function __construct(private App $app) {}

    /** Tool steps a job may take at each effort level. */
    public const STEPS = ['low' => 15, 'medium' => 30, 'high' => 60, 'xhigh' => 100, 'max' => 200];

    /** $maxUsd: null = the default cap (TALIXIO_JOB_MAX_USD); capped at TALIXIO_JOB_MAX_USD_LIMIT. $effort: '' = TALIXIO_JOB_EFFORT. $folder: where the report is saved ('' = the Inbox). */
    public function create(string $title, string $instructions, string $tier, array $tags = [], ?float $maxUsd = null, string $effort = '', string $folder = ''): int
    {
        if (trim($folder) !== '') { $folder = Library::normalize($folder); Library::guard($folder); } else $folder = '';
        $state = $this->app->budget()->status()['state'];
        if ($state !== 'ok') throw new \RuntimeException("budget is $state: not starting background jobs. Do it yourself briefly or tell the user.");
        $active = count($this->app->store->jobsWithStatus('queued')) + count($this->app->store->jobsWithStatus('running'));
        if ($active >= $this->app->cfg->maxParallelJobs * 3) throw new \RuntimeException("$active jobs already queued or running; wait for some to finish");
        return $this->app->store->addJob($title, $instructions, $tier, $this->cap($maxUsd), 'agent', null, $this->app->space, $tags, $this->effort($effort), null, $folder); // the report comes back in this space
    }

    /** A job's cost cap: the default, or the one asked for within TALIXIO_JOB_MAX_USD_LIMIT (0 = no cap, only when the limit is 0 too). */
    public function cap(?float $usd): float
    {
        $c = $this->app->cfg;
        if ($usd === null || $usd < 0) return $c->jobMaxUsd;
        if ($usd == 0) return $c->jobMaxUsdLimit == 0 ? 0.0 : $c->jobMaxUsdLimit;
        return $c->jobMaxUsdLimit > 0 ? min($usd, $c->jobMaxUsdLimit) : $usd;
    }

    private function effort(string $e): string
    {
        if ($e !== '' && !in_array($e, Tier::EFFORTS, true)) throw new \InvalidArgumentException('effort: ' . implode(', ', Tier::EFFORTS));
        return $e;
    }

    /** Meeting recording: transcribe, then summarise. Not subject to the parallel-job limit check (the user asked for it). */
    public function createMeeting(int $fileId, string $note = '', int $space = 0): int
    {
        $f = $this->app->files()->get($fileId);
        $sp = $space ? $this->app->spaces()->get($space) : null;
        if ($f && $f['folder'] === Library::INBOX) $this->app->library()->moveFile($fileId, ($sp['folder'] ?? '') !== '' ? $sp['folder'] . '/Meetings' : Library::MEETINGS);
        $title = 'Meeting summary: ' . ($f ? preg_replace('/\.[a-z0-9]+$/i', '', $f['name']) : "recording #$fileId");
        $instructions = "Summarise this meeting for the user." . ($note !== '' ? " The user said: \"$note\"." : '')
            . "\n\nWrite, in the meeting's language:\n1. **Summary** (5-10 lines)\n2. **Decisions**\n3. **Action items**: who, what, by when (\"not stated\" if unknown)\n"
            . "4. **Open questions / follow-ups**\n5. **Participants** (as named in the transcript)\nBe factual: only what the transcript supports.";
        return $this->app->store->addJob($title, $instructions, $this->app->cfg->meetingTier, max($this->app->cfg->jobMaxUsd, 1.0), 'meeting', $fileId, $space, ['meeting']);
    }

    /** Run a finished job again (same instructions), optionally with another tier, cap or effort. Returns the new job id. */
    public function rerun(int $id, string $tier = '', ?float $maxUsd = null, string $effort = ''): int
    {
        $j = $this->app->store->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if (in_array($j['status'], ['queued', 'running'], true)) throw new \InvalidArgumentException('This job is still running.');
        if ($j['kind'] === 'reflect') throw new \InvalidArgumentException('Learning jobs are not run again.');
        if ($tier !== '' && !in_array($tier, Tier::NAMES, true)) throw new \InvalidArgumentException('unknown tier');
        if ($this->app->budget()->status()['state'] === 'blocked') throw new \InvalidArgumentException('The budget is used up.');
        return $this->app->store->addJob($j['title'], $j['instructions'], $tier ?: $j['tier'], $maxUsd !== null ? $this->cap($maxUsd) : (float)$j['max_cost_usd'], $j['kind'],
            $j['file_id'] !== null ? (int)$j['file_id'] : null, (int)$j['space'], Store::tagList($j['tags']), $this->effort($effort) ?: (string)($j['effort'] ?? ''), null, (string)($j['folder'] ?? ''));
    }

    /**
     * Carry on a job that stopped (cost cap, error) or that should go further: a new job with the same instructions
     * that starts from the steps and findings of the earlier run(s). $maxUsd: null = the same cap again.
     */
    public function continue(int $id, ?float $maxUsd = null, string $effort = '', string $note = ''): int
    {
        $j = $this->app->store->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if (in_array($j['status'], ['queued', 'running'], true)) throw new \InvalidArgumentException('This job is still running.');
        if ($j['kind'] !== 'agent') throw new \InvalidArgumentException('Only research jobs can be continued; run it again instead.');
        if ($this->app->budget()->status()['state'] === 'blocked') throw new \InvalidArgumentException('The budget is used up.');
        $note = trim($note) !== '' ? "\n\n# The user adds\n" . trim($note) : '';
        return $this->app->store->addJob(preg_replace('/ \(continued( \d+)?\)$/', '', $j['title']) . ' (continued)', $j['instructions'] . $note, $j['tier'],
            $maxUsd !== null ? $this->cap($maxUsd) : (float)$j['max_cost_usd'], 'agent', null, (int)$j['space'], Store::tagList($j['tags']),
            $this->effort($effort) ?: (string)$j['effort'], $id, (string)($j['folder'] ?? ''));
    }

    /** What the earlier runs of a continued job did: their tool steps and reports, oldest first, within a size limit. */
    private function earlierWork(array $j): string
    {
        $chain = [];
        for ($p = $j['parent'] ?? null; $p && count($chain) < 10 && ($pj = $this->app->store->job((int)$p)); $p = $pj['parent']) array_unshift($chain, $pj);
        if (!$chain) return '';
        $parts = [];
        foreach ($chain as $n => $pj) {
            $steps = array_map(fn($s) => "- {$s['tool']} " . mb_substr($s['args'], 0, 300) . "\n  → " . str_replace("\n", ' ', mb_substr($s['result'], 0, 700)), $this->app->store->jobSteps((int)$pj['id']));
            $parts[] = '## Run ' . ($n + 1) . " (job #{$pj['id']}, {$pj['status']}, \$" . sprintf('%.2f', $pj['cost_usd']) . ")\n### Steps\n"
                . ($steps ? implode("\n", $steps) : '(none recorded)') . "\n### Its report or last words\n" . ($pj['result'] ?: '(none)');
        }
        $text = implode("\n\n", $parts);
        if (mb_strlen($text) > 60000) $text = '…' . mb_substr($text, -60000); // the latest work matters most
        return "# Continuing earlier work\nThis task was started before and stopped (usually at its cost cap). Below is what was already done. "
            . "Don't redo those steps: use their findings, carry on with what is missing, then write the complete final report "
            . "(including the earlier findings, not only the new ones).\n\n$text";
    }

    public function setTags(int $id, array|string $tags): array
    {
        $this->app->store->job($id) ?? throw new \InvalidArgumentException("no job $id");
        $this->app->store->updateJob($id, ['tags' => Store::tagString($tags)]);
        return $this->app->store->job($id);
    }

    public function cancel(int $id): bool
    {
        $j = $this->app->store->job($id);
        if (!$j || !in_array($j['status'], ['queued', 'running'], true)) return false;
        $this->app->store->updateJob($id, ['status' => 'cancelled', 'finished' => time()]);
        return true;
    }

    /** Finished jobs are put away on their own after this many days (still searchable under Archived). */
    public const ARCHIVE_DAYS = 30;

    /** Put a finished job away (or bring it back). Its report note in the library is not touched. */
    public function archive(int $id, bool $archived = true): array
    {
        $j = $this->app->store->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if ($archived && in_array($j['status'], ['queued', 'running'], true)) throw new \InvalidArgumentException('This job is still running.');
        $this->app->store->updateJob($id, ['archived' => (int)$archived]);
        return $this->app->store->job($id);
    }

    /** Put away every finished job of a space and/or tag (all of them by default). Returns how many. */
    public function archiveFinished(?int $space = null, string $tag = '', int $olderThan = 0): int
    {
        $n = 0;
        foreach ($this->app->store->jobs(100000, $space, $tag, false) as $j) {
            if (in_array($j['status'], ['queued', 'running'], true) || ($olderThan && (int)($j['finished'] ?: $j['created']) > $olderThan)) continue;
            $this->app->store->updateJob((int)$j['id'], ['archived' => 1]); $n++;
        }
        return $n;
    }

    /** Jobs done without trouble and without tags are put away sooner: their report is in the chat and the library. */
    public const ARCHIVE_DONE_DAYS = 2;

    /** Worker, hourly: plain done jobs after ARCHIVE_DONE_DAYS, the others (failed, stopped, tagged to keep) after ARCHIVE_DAYS. */
    public function autoArchive(int $now): int
    {
        $n = 0;
        foreach ($this->app->store->jobs(100000, null, '', false) as $j) {
            if ($j['status'] !== 'done' || trim((string)($j['tags'] ?? '')) !== '' || (int)($j['finished'] ?: $j['created']) > $now - self::ARCHIVE_DONE_DAYS * 86400) continue;
            $this->app->store->updateJob((int)$j['id'], ['archived' => 1]); $n++;
        }
        return $n + $this->archiveFinished(null, '', $now - self::ARCHIVE_DAYS * 86400);
    }

    /** Delete a finished job and its steps for good. Its report note stays in the library; its costs stay in the usage history. */
    public function delete(int $id): void
    {
        $s = $this->app->store;
        $j = $s->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if (in_array($j['status'], ['queued', 'running'], true)) throw new \InvalidArgumentException('This job is still running: cancel it first.');
        if ($t = $s->q('SELECT title FROM tasks WHERE job = ?', [$id])->fetch()) throw new \InvalidArgumentException("The routine \"{$t['title']}\" runs this job: delete the routine first, or archive the job instead.");
        $s->q('DELETE FROM tool_log WHERE job_id = ?', [$id]);
        $s->q('UPDATE jobs SET parent = NULL WHERE parent = ?', [$id]);
        $s->q('DELETE FROM jobs WHERE id = ?', [$id]);
    }

    /**
     * Make a routine that runs this job again (same instructions, tier, effort, cap and report folder):
     * $when in plain words ("every monday at 8:00"), or the older $cron (5 fields, local time) / $at ('YYYY-MM-DD HH:MM'). Returns the routine.
     */
    public function schedule(int $id, string $cron = '', string $at = '', string $when = ''): array
    {
        $s = $this->app->store;
        $j = $s->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if ($j['kind'] !== 'agent') throw new \InvalidArgumentException('Only research jobs can be scheduled.');
        ['cron' => $cron, 'next' => $next] = Tools::when(['when' => $when, 'cron' => $cron, 'at' => $at]);
        $title = preg_replace('/ \(continued( \d+)?\)$/', '', $j['title']);
        $tid = $s->addTask($title, "Runs background job #$id again: $title", $cron ?: null, $next, (string)$j['tier'], (int)$j['space'], $id);
        return $s->task($tid);
    }

    /** A routine made with schedule() is due: start the job again. Returns what to tell the user. */
    public function runScheduled(array $task): string
    {
        $new = $this->rerun((int)$task['job']);
        return "Job #$new started again: the report will come in the chat when it is done.";
    }

    /** Worker startup: jobs left "running" by a previous worker were killed with it. */
    public const INTERRUPTED = 'Interrupted by a restart.';

    /**
     * At worker start: jobs left running were killed by the restart (an update, a reboot). Each is marked failed and
     * started again once (a research job carries on from its steps); a job that was already a retry is not retried again.
     * The user gets one message listing them. Returns [job id => new job id or null].
     */
    public function recoverInterrupted(): array
    {
        $store = $this->app->store;
        $out = [];
        foreach ($store->jobsWithStatus('running') as $j) {
            $id = (int)$j['id'];
            $store->updateJob($id, ['status' => 'failed', 'result' => self::INTERRUPTED, 'finished' => time()]);
            $new = null;
            if ($j['kind'] !== 'reflect' && !$this->wasRetried($j)) {
                try { $new = $j['kind'] === 'agent' ? $this->continue($id) : $this->rerun($id); }
                catch (\Throwable $e) { Log::warn("job $id: not restarted: {$e->getMessage()}"); }
            }
            $out[$id] = $new;
            Log::info("job $id interrupted by a restart" . ($new ? ", started again as job $new" : ''));
        }
        $shown = array_filter($store->q('SELECT id, title, kind, space FROM jobs WHERE id IN (' . (implode(',', array_keys($out)) ?: '0') . ')')->fetchAll(), fn($j) => $j['kind'] !== 'reflect');
        if ($shown) {
            $lines = array_map(fn($j) => '- ' . $j['title'] . ' (#' . $j['id'] . ') ' . ($out[(int)$j['id']]
                ? I18n::t($this->app, '→ started again as job #{id}', ['id' => $out[(int)$j['id']]])
                : I18n::t($this->app, '→ not started again: run it again from Jobs if you still need it')), $shown);
            $text = I18n::t($this->app, 'The assistant restarted (an update or a reboot) while these jobs were running:') . "\n" . implode("\n", $lines);
            $space = (int)reset($shown)['space'];
            $store->addMessage('assistant', 'job', "⚠️ $text", null, [], $space); // in the thread, so the assistant knows too
            try {
                $this->app->notifier()->notify(I18n::t($this->app, 'Jobs interrupted by a restart'), $text, $space);
            } catch (\Throwable $e) { Log::warn("interrupted jobs: not notified: {$e->getMessage()}"); }
        }
        return $out;
    }

    /** Whether this job is itself the restart of a job a restart interrupted (so it isn't restarted forever). */
    private function wasRetried(array $j): bool
    {
        if (!empty($j['parent'])) return ($this->app->store->job((int)$j['parent'])['result'] ?? '') === self::INTERRUPTED;
        return (int)$this->app->store->q("SELECT COUNT(*) FROM jobs WHERE title = ? AND kind = ? AND id <> ? AND result = ? AND finished >= ?",
            [$j['title'], $j['kind'], (int)$j['id'], self::INTERRUPTED, time() - 86400])->fetchColumn() > 0;
    }

    /** Worker tick: reap finished processes, stop cancelled ones, start queued jobs. */
    public function dispatch(): void
    {
        $store = $this->app->store;
        foreach (self::$procs as $id => $proc) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                proc_close($proc);
                unset(self::$procs[$id]);
                if (($store->job($id)['status'] ?? '') === 'running') { // crashed without recording an outcome
                    $store->updateJob($id, ['status' => 'failed', 'result' => "The job process exited unexpectedly (code {$st['exitcode']}).", 'finished' => time()]);
                }
            } elseif (($store->job($id)['status'] ?? '') === 'cancelled') {
                proc_terminate($proc);
            }
        }
        if (self::draining($this->app->cfg->dataDir)) return; // an update is waiting for the running jobs to finish
        foreach ($store->jobsWithStatus('queued') as $j) {
            if (count(self::$procs) >= $this->app->cfg->maxParallelJobs) break;
            if (!$store->claimJob((int)$j['id'])) continue;
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'job', (string)$j['id']], [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes);
            if (is_resource($proc)) self::$procs[(int)$j['id']] = $proc;
            else $store->updateJob((int)$j['id'], ['status' => 'failed', 'result' => 'Could not start the job process.', 'finished' => time()]);
        }
    }

    public static function runningCount(): int { return count(self::$procs); }

    /** The update asks the worker not to start new jobs while it waits for the running ones (for 15 minutes at most). */
    public static function drainFile(string $dataDir): string { return "$dataDir/update-draining"; }
    public static function draining(string $dataDir): bool { return (@filemtime(self::drainFile($dataDir)) ?: 0) > time() - 900; }

    /** Runs inside the job process. */
    public function runJob(int $id): void
    {
        $this->app->jobId = $id;
        try { $this->runJobAs($id); } finally {
            if ($this->tuned) $this->tuned[0]->tiers = $this->tuned[1]; // the configuration tuned (the space's AI account or the main one)
            $this->tuned = null; $this->app->notice = ''; $this->app->jobId = null;
        }
    }

    private function runJobAs(int $id): void
    {
        $store = $this->app->store;
        $j = $store->job($id) ?? throw new \RuntimeException("no job $id");
        if ($j['status'] === 'queued') $store->claimJob($id);
        $this->app->setSpace((int)$j['space']); // library searches start in the space's folder; its confidentiality level applies
        Privacy::$taint = 0; Privacy::trustAgain();
        $max = (float)$j['max_cost_usd'];
        $effort = $this->tune((string)($j['effort'] ?? ''));
        $warned = false;
        $guard = function (float $cost) use ($id, $max, $store, &$warned) {
            $store->updateJob($id, ['cost_usd' => $cost]);
            if (($store->job($id)['status'] ?? '') === 'cancelled') throw new Abort('cancelled');
            if ($max > 0 && $cost >= $max) throw new Abort(sprintf('cost cap of $%.2f reached', $max));
            if ($max > 0 && !$warned && $cost >= 0.8 * $max) { // let it write its report rather than lose the work
                $warned = true;
                $this->app->notice = sprintf('[Job budget: $%.2f of $%.2f used. Stop researching now and write your final report with what you have; say what is left to do.]', $cost, $max);
            }
        };
        $brief = "# This background job\nEffort: $effort. " . ($max > 0 ? sprintf('Cost cap: $%.2f; you are warned at 80%%, then stopped at 100%% and the work is lost, so pace yourself and keep notes of findings as you go. ', $max) : '')
            . 'Work thoroughly, then write one complete, self-contained report.';
        try {
            $task = $j['instructions'];
            if (!empty($j['parent'])) $task .= "\n\n" . $this->earlierWork($j);
            if ($j['kind'] === 'meeting') {
                $store->updateJob($id, ['result' => 'Transcribing…']);
                $f = $this->app->files()->get((int)$j['file_id']) ?? throw new \RuntimeException('recording not found');
                $transcript = $this->app->files()->text((int)$f['id']);
                $store->updateJob($id, ['result' => 'Summarising…']);
                $task .= "\n\nRecording: {$f['name']}, " . date('Y-m-d H:i', (int)$f['created']) . ". The full transcript is saved as file #{$f['id']} (searchable later).\n\n# Transcript\n" . mb_substr($transcript, 0, 300000);
            }
            if ($j['kind'] === 'reflect') { $this->reflect($j, $guard); return; }
            if ($j['kind'] === 'eval') { // the assessor's A/B of a role improvement (agent factory, phase 2)
                $text = $this->app->assessor()->runEvaluation($j, $guard);
                $store->updateJob($id, ['status' => 'done', 'result' => $text, 'cost_usd' => (float)$store->job($id)['cost_usd'], 'finished' => time()]);
                $this->report($id);
                return;
            }
            [$text, $meta] = $this->app->agent()->run('job', $j['tier'], 'job', [['role' => 'user', 'content' => $task]], $brief, 'job', $id, $guard);
            if ($j['kind'] === 'meeting') $store->addDoc('summary', $id, 'meeting summary: ' . $j['title'], $text, null, (int)($j['space'] ?? 0)); // its space's level applies
            $store->updateJob($id, ['status' => 'done', 'result' => $text ?: '(empty report)', 'cost_usd' => $meta['cost'], 'finished' => time()]);
            if (trim((string)$text) !== '') $this->saveReport($store->job($id), $text);
        } catch (Abort $e) {
            if ($e->getMessage() === 'cancelled') { if ($j['kind'] === 'worker') $this->app->director()->onWorkerDone($id); return; } // cancelled: no chat report, but the directive may now be done
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Stopped: ' . $e->getMessage(), 'finished' => time()]);
        } catch (\Throwable $e) {
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Error: ' . $e->getMessage(), 'finished' => time()]);
        }
        if ($j['kind'] === 'worker') $this->app->director()->onWorkerDone($id); // the directive reports once, not each worker
        elseif ($j['kind'] !== 'reflect') $this->report($id); // learning is quiet unless it learned something
    }

    /** In the job's process: every tier gets the job's effort and its number of tool steps. Returns the effort. */
    private function tune(string $effort): string
    {
        $c = $this->app->cfg;
        $effort = in_array($effort, Tier::EFFORTS, true) ? $effort : $c->jobEffort;
        $this->tuned ??= [$c, $c->tiers];
        $c->tiers = array_map(fn(Tier $t) => clone $t, $c->tiers);
        foreach ($c->tiers as $t) {
            $t->maxSteps = max($t->maxSteps, self::STEPS[$effort]);
            // OpenAI-compatible servers only get it where it was set (plain chat models refuse it)
            if (!in_array($c->provider, ['openai', 'sovereign'], true) || $t->reasoningEffort !== '') $t->reasoningEffort = $effort;
        }
        return $effort;
    }

    /** Learning job: may save or update a skill; tells the user only when it did. */
    private function reflect(array $j, callable $guard): void
    {
        $id = (int)$j['id']; $store = $this->app->store;
        $before = (int)$store->q('SELECT COALESCE(MAX(id),0) FROM tool_log')->fetchColumn();
        [$text, $meta] = $this->app->agent()->run('learn', 'fast', 'reflect', [['role' => 'user', 'content' => $j['instructions']]], '', 'reflect', $id, $guard);
        $saved = $store->q("SELECT args, result FROM tool_log WHERE id > ? AND tool = 'skill_save' AND result NOT LIKE 'error%'", [$before])->fetchAll();
        $store->updateJob($id, ['status' => 'done', 'result' => $saved ? implode("\n", array_column($saved, 'result')) : 'Nothing worth keeping.', 'cost_usd' => $meta['cost'], 'finished' => time()]);
        foreach ($saved as $s) {
            $a = json_decode($s['args'], true) ?: [];
            $store->addMessage('assistant', 'learn', '📘 ' . I18n::t($this->app, (str_starts_with($s['result'], 'updated') ? 'Updated' : 'Learned') . " a skill: **{name}**: {description}\nYou can see or edit it on the Memory page.",
                ['name' => Skills::slug($a['name'] ?? '?'), 'description' => $a['description'] ?? '']));
        }
    }

    /**
     * Keep the full report in the library, so nothing is lost when the chat relays it in short: in the folder asked for,
     * next to the recording for a meeting, else in its space's folder or the Inbox. A failure here doesn't fail the job.
     */
    private function saveReport(array $j, string $text): void
    {
        try {
            $folder = (string)($j['folder'] ?? '');
            if ($folder === '' && $j['kind'] === 'meeting' && $j['file_id'] !== null) $folder = (string)($this->app->files()->get((int)$j['file_id'])['folder'] ?? '');
            if ($folder === '' && (int)($j['space'] ?? 0) > 0) $folder = (string)($this->app->spaces()->get((int)$j['space'])['folder'] ?? ''); // stays at its space's level
            if ($folder === '') $folder = Library::INBOX;
            $p = $this->app->privacy(); $level = $p->effective();
            if ($p->writeDown($folder, $level) !== null) { // what it read stays at its level: its space's folder, or the chat only
                $own = (int)($j['space'] ?? 0) > 0 ? (string)($this->app->spaces()->get((int)$j['space'])['folder'] ?? '') : '';
                if ($own === '' || $p->writeDown($own, $level) !== null) { Log::warn("job {$j['id']}: report not saved in the library: " . $p->writeDown($folder, $level)); return; }
                $folder = $own;
            }
            $this->app->library()->ensureFolder($folder);
            $title = trim(preg_replace('#[/\\\\:*?"<>|\x00-\x1f]+#', ' ', (string)$j['title']));
            $name = ($j['kind'] === 'meeting' ? '' : 'Job - ') . mb_substr($title, 0, 60) . ' - ' . date('Y-m-d H.i');
            $f = $this->app->library()->saveNote($folder, $name, "# {$j['title']}\n\n_" . date('Y-m-d H:i') . " · job #{$j['id']}_\n\n" . $text);
            $this->app->store->updateJob((int)$j['id'], ['report_file' => (int)$f['id']]);
        } catch (\Throwable $e) {
            Log::warn("job {$j['id']}: report not saved: " . $e->getMessage());
        }
    }

    /** Post the outcome in the main thread; the main assistant relays it to the user. */
    private function report(int $id): void
    {
        $this->app->jobId = null; // relaying the report is the chat's work, not the job's
        $j = $this->app->store->job($id);
        $head = $j['status'] === 'done' ? 'finished' : 'failed';
        $how = $j['kind'] === 'meeting'
            ? "Give the user the meeting summary as written (keep the structure), then offer to add the user's own action items as tasks on the board (board_add, with due dates when stated)."
            : "Relay what matters to the user concisely, with sources; don't mention job numbers unless useful.";
        if ($j['status'] === 'failed' && str_contains((string)$j['result'], 'cost cap')) {
            $how = "It reached its cost cap before finishing. Tell the user, with whatever it found, and that it can carry on from where it stopped: "
                . "they say e.g. \"continue job #{$j['id']}\" (optionally with a budget) or press Continue in Jobs. Don't continue it yourself unless they ask.";
        }
        if (!empty($j['report_file']) && ($f = $this->app->files()->get((int)$j['report_file']))) {
            $how .= sprintf(' The full report is saved in the library as [%s](/files/%d) in %s: give the user this link, and offer to file it elsewhere (move it) if they want.',
                str_replace(['[', ']'], '', $f['name']), $f['id'], $f['folder']);
        }
        $reply = $this->app->agent()->chat('job', "[Background job #{$j['id']} \"{$j['title']}\" $head. Its report follows. $how]\n\n{$j['result']}", 'standard', [], null, (int)$j['space']);
        $this->app->notifier()->notify($j['title'], $reply, (int)$j['space']);
    }
}
