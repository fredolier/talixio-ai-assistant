<?php
declare(strict_types=1);
namespace Talixio;

/** Background sub-agents. The worker starts each job as its own process (`talixio job ID`), up to TALIXIO_MAX_PARALLEL_JOBS at once. */
final class Jobs
{
    /** @var array<int,resource> job id => process, owned by the worker */
    private static array $procs = [];

    public function __construct(private App $app) {}

    /** Tool steps a job may take at each effort level. */
    public const STEPS = ['low' => 15, 'medium' => 30, 'high' => 60, 'xhigh' => 100, 'max' => 200];

    /** $maxUsd: null = the default cap (TALIXIO_JOB_MAX_USD); capped at TALIXIO_JOB_MAX_USD_LIMIT. $effort: '' = TALIXIO_JOB_EFFORT. */
    public function create(string $title, string $instructions, string $tier, array $tags = [], ?float $maxUsd = null, string $effort = ''): int
    {
        $state = $this->app->budget()->status()['state'];
        if ($state !== 'ok') throw new \RuntimeException("budget is $state: not starting background jobs. Do it yourself briefly or tell the user.");
        $active = count($this->app->store->jobsWithStatus('queued')) + count($this->app->store->jobsWithStatus('running'));
        if ($active >= $this->app->cfg->maxParallelJobs * 3) throw new \RuntimeException("$active jobs already queued or running; wait for some to finish");
        return $this->app->store->addJob($title, $instructions, $tier, $this->cap($maxUsd), 'agent', null, $this->app->space, $tags, $this->effort($effort)); // the report comes back in this space
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
            $j['file_id'] !== null ? (int)$j['file_id'] : null, (int)$j['space'], Store::tagList($j['tags']), $this->effort($effort) ?: (string)($j['effort'] ?? ''));
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
            $this->effort($effort) ?: (string)$j['effort'], $id);
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

    /** Worker startup: jobs left "running" by a previous worker were killed with it. */
    public function recoverInterrupted(): void
    {
        foreach ($this->app->store->jobsWithStatus('running') as $j) {
            $this->app->store->updateJob((int)$j['id'], ['status' => 'failed', 'result' => 'Interrupted by a restart.', 'finished' => time()]);
        }
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
        foreach ($store->jobsWithStatus('queued') as $j) {
            if (count(self::$procs) >= $this->app->cfg->maxParallelJobs) break;
            if (!$store->claimJob((int)$j['id'])) continue;
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'job', (string)$j['id']], [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes);
            if (is_resource($proc)) self::$procs[(int)$j['id']] = $proc;
            else $store->updateJob((int)$j['id'], ['status' => 'failed', 'result' => 'Could not start the job process.', 'finished' => time()]);
        }
    }

    public static function runningCount(): int { return count(self::$procs); }

    /** Runs inside the job process. */
    public function runJob(int $id): void
    {
        $tiers = $this->app->cfg->tiers;
        $this->app->jobId = $id;
        try { $this->runJobAs($id); } finally { $this->app->cfg->tiers = $tiers; $this->app->notice = ''; $this->app->jobId = null; }
    }

    private function runJobAs(int $id): void
    {
        $store = $this->app->store;
        $j = $store->job($id) ?? throw new \RuntimeException("no job $id");
        if ($j['status'] === 'queued') $store->claimJob($id);
        $this->app->space = (int)$j['space']; // library searches start in the space's folder
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
            [$text, $meta] = $this->app->agent()->run('job', $j['tier'], 'job', [['role' => 'user', 'content' => $task]], $brief, 'job', $id, $guard);
            if ($j['kind'] === 'meeting') $store->addDoc('summary', $id, 'meeting summary: ' . $j['title'], $text);
            $store->updateJob($id, ['status' => 'done', 'result' => $text ?: '(empty report)', 'cost_usd' => $meta['cost'], 'finished' => time()]);
        } catch (Abort $e) {
            if ($e->getMessage() === 'cancelled') return; // the user or assistant cancelled it: nothing to report
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Stopped: ' . $e->getMessage(), 'finished' => time()]);
        } catch (\Throwable $e) {
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Error: ' . $e->getMessage(), 'finished' => time()]);
        }
        if ($j['kind'] !== 'reflect') $this->report($id); // learning is quiet unless it learned something
    }

    /** In the job's process: every tier gets the job's effort and its number of tool steps. Returns the effort. */
    private function tune(string $effort): string
    {
        $c = $this->app->cfg;
        $effort = in_array($effort, Tier::EFFORTS, true) ? $effort : $c->jobEffort;
        $c->tiers = array_map(fn(Tier $t) => clone $t, $c->tiers);
        foreach ($c->tiers as $t) {
            $t->maxSteps = max($t->maxSteps, self::STEPS[$effort]);
            // OpenAI-compatible servers only get it where it was set (plain chat models refuse it)
            if ($c->provider !== 'openai' || $t->reasoningEffort !== '') $t->reasoningEffort = $effort;
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
            $store->addMessage('assistant', 'learn', '📘 ' . (str_starts_with($s['result'], 'updated') ? 'Updated' : 'Learned') . " a skill: **" . Skills::slug($a['name'] ?? '?') . "**: "
                . ($a['description'] ?? '') . "\nYou can see or edit it on the Memory page.");
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
        $reply = $this->app->agent()->chat('job', "[Background job #{$j['id']} \"{$j['title']}\" $head. Its report follows. $how]\n\n{$j['result']}", 'standard', [], null, (int)$j['space']);
        $this->app->notifier()->notify($j['title'], $reply, (int)$j['space']);
    }
}
