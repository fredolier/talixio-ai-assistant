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
            [$text, $meta] = $this->app->agent()->run('job', $j['tier'], 'job', [['role' => 'user', 'content' => $j['instructions']]], '', 'job', $id, $guard);
            $store->updateJob($id, ['status' => 'done', 'result' => $text ?: '(empty report)', 'cost_usd' => $meta['cost'], 'finished' => time()]);
        } catch (Abort $e) {
            if ($e->getMessage() === 'cancelled') return; // the user or assistant cancelled it: nothing to report
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Stopped: ' . $e->getMessage(), 'finished' => time()]);
        } catch (\Throwable $e) {
            $store->updateJob($id, ['status' => 'failed', 'result' => 'Error: ' . $e->getMessage(), 'finished' => time()]);
        }
        $this->report($id);
    }

    /** Post the outcome in the main thread; the main assistant relays it to the user. */
    private function report(int $id): void
    {
        $j = $this->app->store->job($id);
        $head = $j['status'] === 'done' ? 'finished' : 'failed';
        $reply = $this->app->agent()->chat('job',
            "[Background job #{$j['id']} \"{$j['title']}\" $head. Its report follows. Relay what matters to the user concisely, with sources; don't mention job numbers unless useful.]\n\n{$j['result']}");
        $this->app->notifier()->notify($j['title'], $reply);
    }
}
