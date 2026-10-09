<?php
declare(strict_types=1);
namespace Talixio;

/** Background sub-agents. The worker starts each job as its own process (`talixio job ID`), up to TALIXIO_MAX_PARALLEL_JOBS at once. */
final class Jobs
{
    /** @var array<int,resource> job id => process, owned by the worker */
    private static array $procs = [];

    public function __construct(private App $app) {}

    public function create(string $title, string $instructions, string $tier): int
    {
        $state = $this->app->budget()->status()['state'];
        if ($state !== 'ok') throw new \RuntimeException("budget is $state: not starting background jobs. Do it yourself briefly or tell the user.");
        $active = count($this->app->store->jobsWithStatus('queued')) + count($this->app->store->jobsWithStatus('running'));
        if ($active >= $this->app->cfg->maxParallelJobs * 3) throw new \RuntimeException("$active jobs already queued or running; wait for some to finish");
        return $this->app->store->addJob($title, $instructions, $tier, $this->app->cfg->jobMaxUsd);
    }

    /** Meeting recording: transcribe, then summarise. Not subject to the parallel-job limit check (the user asked for it). */
    public function createMeeting(int $fileId, string $note = ''): int
    {
        $f = $this->app->files()->get($fileId);
        if ($f && $f['folder'] === Library::INBOX) $this->app->library()->moveFile($fileId, Library::MEETINGS);
        $title = 'Meeting summary: ' . ($f ? preg_replace('/\.[a-z0-9]+$/i', '', $f['name']) : "recording #$fileId");
        $instructions = "Summarise this meeting for the user." . ($note !== '' ? " The user said: \"$note\"." : '')
            . "\n\nWrite, in the meeting's language:\n1. **Summary** (5-10 lines)\n2. **Decisions**\n3. **Action items**: who, what, by when (\"not stated\" if unknown)\n"
            . "4. **Open questions / follow-ups**\n5. **Participants** (as named in the transcript)\nBe factual: only what the transcript supports.";
        return $this->app->store->addJob($title, $instructions, $this->app->cfg->meetingTier, max($this->app->cfg->jobMaxUsd, 1.0), 'meeting', $fileId);
    }

    /** Run a finished job again (same instructions), optionally with another tier. Returns the new job id. */
    public function rerun(int $id, string $tier = ''): int
    {
        $j = $this->app->store->job($id) ?? throw new \InvalidArgumentException("no job $id");
        if (in_array($j['status'], ['queued', 'running'], true)) throw new \InvalidArgumentException('This job is still running.');
        if ($j['kind'] === 'reflect') throw new \InvalidArgumentException('Learning jobs are not run again.');
        if ($tier !== '' && !in_array($tier, Tier::NAMES, true)) throw new \InvalidArgumentException('unknown tier');
        if ($this->app->budget()->status()['state'] === 'blocked') throw new \InvalidArgumentException('The budget is used up.');
        return $this->app->store->addJob($j['title'], $j['instructions'], $tier ?: $j['tier'], (float)$j['max_cost_usd'], $j['kind'], $j['file_id'] !== null ? (int)$j['file_id'] : null);
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
        $store = $this->app->store;
        $j = $store->job($id) ?? throw new \RuntimeException("no job $id");
        if ($j['status'] === 'queued') $store->claimJob($id);
        $max = (float)$j['max_cost_usd'];
        $guard = function (float $cost) use ($id, $max, $store) {
            $store->updateJob($id, ['cost_usd' => $cost]);
            if (($store->job($id)['status'] ?? '') === 'cancelled') throw new Abort('cancelled');
            if ($max > 0 && $cost >= $max) throw new Abort(sprintf('cost cap of $%.2f reached', $max));
        };
        try {
            $task = $j['instructions'];
            if ($j['kind'] === 'meeting') {
                $store->updateJob($id, ['result' => 'Transcribing…']);
                $f = $this->app->files()->get((int)$j['file_id']) ?? throw new \RuntimeException('recording not found');
                $transcript = $this->app->files()->text((int)$f['id']);
                $store->updateJob($id, ['result' => 'Summarising…']);
                $task .= "\n\nRecording: {$f['name']}, " . date('Y-m-d H:i', (int)$f['created']) . ". The full transcript is saved as file #{$f['id']} (searchable later).\n\n# Transcript\n" . mb_substr($transcript, 0, 300000);
            }
            if ($j['kind'] === 'reflect') { $this->reflect($j, $guard); return; }
            [$text, $meta] = $this->app->agent()->run('job', $j['tier'], 'job', [['role' => 'user', 'content' => $task]], '', 'job', $id, $guard);
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
        $j = $this->app->store->job($id);
        $head = $j['status'] === 'done' ? 'finished' : 'failed';
        $how = $j['kind'] === 'meeting'
            ? "Give the user the meeting summary as written (keep the structure), then offer to schedule reminders for the user's own action items."
            : "Relay what matters to the user concisely, with sources; don't mention job numbers unless useful.";
        $reply = $this->app->agent()->chat('job', "[Background job #{$j['id']} \"{$j['title']}\" $head. Its report follows. $how]\n\n{$j['result']}");
        $this->app->notifier()->notify($j['title'], $reply);
    }
}
