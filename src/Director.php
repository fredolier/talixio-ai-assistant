<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The director runs a team of workers toward one goal (agent factory, phase 3 — see docs/design/agent-factory.md).
 *
 * The director is not a separate agent: it is the ordinary chat assistant given these tools. A request that earns a
 * team becomes a DIRECTIVE; each WORKER is one role running as a background job (so it reuses the whole jobs engine —
 * tiers, cost caps, cancel, recovery). Workers don't each report to the chat; when the last one finishes the directive
 * is finalised once, its workers' outcomes aggregated, and the anchor metric (acceptance + cost) recorded per role so
 * the assessor (phase 2) can learn from real runs.
 *
 * This is tiers A+B (headless web + sandboxed shell, both already in the 'job' toolset). Priority is stored and shown;
 * making a raised directive actually slow the others, and tier C (a disposable desktop), are later refinements.
 */
final class Director
{
    public function __construct(private App $app) {}

    public const PRIORITY = ['p1' => 1, 'p2' => 2, 'p3' => 3];

    /**
     * Open a directive and queue its workers. $workers: list of ['role'=>name, 'task'=>what this worker does, 'tier'?].
     * Every role must already exist in the registry (role_find / role_save first) — the director reuses before it builds.
     */
    public function open(string $goal, string $title, array $workers, int $priority = 2): string
    {
        $workers = array_values(array_filter($workers, fn($w) => is_array($w) && trim((string)($w['role'] ?? '')) !== ''));
        if (!$workers) return 'error: a directive needs at least one worker ([{role, task}]).';
        if (($state = $this->app->budget()->status()['state']) !== 'ok') return "error: budget is $state — not starting a directive now. Do the work inline, or tell the user.";
        $active = count($this->app->store->jobsWithStatus('queued')) + count($this->app->store->jobsWithStatus('running'));
        if ($active + count($workers) > $this->app->cfg->maxParallelJobs * 3) return "error: too many jobs already running ($active); wait for some to finish before opening this directive.";

        $plans = $this->plan($workers);
        if (is_string($plans)) return $plans;
        $did = $this->app->store->addDirective(trim($title) ?: mb_substr($goal, 0, 60), trim($goal), max(1, min(3, $priority)), (int)$this->app->space);
        $cap = $this->app->jobs()->cap(null);
        foreach ($plans as $p) {
            $instructions = self::seed($p['role'], $p['brief'], $goal, $p['task']);
            $this->app->store->addJob("{$p['role']}: " . mb_substr($p['task'] ?: $title, 0, 50), $instructions, $p['tier'], $cap, 'worker',
                null, (int)$this->app->space, [], '', null, '', $did, $p['role']);
        }
        return sprintf("directive #%d \"%s\" opened (P%d) with %d worker(s): %s. They run in the background; I'll report when they're done. Track it with directive_status.",
            $did, $this->app->store->directive($did)['title'], max(1, min(3, $priority)), count($plans), implode(', ', array_column($plans, 'role')));
    }

    /**
     * Resolve each worker to a plan: the role must exist in the registry, its brief is read, its tier chosen
     * (worker override → role's tier → standard). Returns the list of plans, or an error string on the first bad role.
     * Shared with the conductor (orchestration.md §5.4) so every agent — in-process worker or Tier-D code session —
     * is spawned from a role the same way; there is no second spawn path.
     * @param list<array{role:string,task?:string,tier?:string}> $workers
     * @return list<array{role:string,tier:string,task:string,brief:string}>|string
     */
    public function plan(array $workers): array|string
    {
        $skills = $this->app->skills();
        $plans = [];
        foreach ($workers as $w) {
            $role = (string)($w['role'] ?? '');
            $meta = $skills->meta($role);
            if (($meta['kind'] ?? '') !== 'role') return "error: no role '$role'. Search with role_find, then create it with role_save before opening the directive.";
            [, $brief] = Skills::parse($skills->read($role, false));
            $tier = in_array($w['tier'] ?? '', Tier::NAMES, true) ? (string)$w['tier'] : (in_array($meta['tier'] ?? '', Tier::NAMES, true) ? (string)$meta['tier'] : 'standard');
            $plans[] = ['role' => Skills::slug($role), 'tier' => $tier, 'task' => trim((string)($w['task'] ?? '')), 'brief' => $brief];
        }
        return $plans;
    }

    /** The worker's seed prompt from its role — identical for an in-process worker and a Tier-D code session. */
    public static function seed(string $role, string $brief, string $goal, string $task): string
    {
        return "You are the **$role** role on a team working toward this goal:\n> " . trim($goal) . "\n\n"
            . "## Your brief\n$brief\n\n## Your task\n" . ($task !== '' ? $task : 'Carry out your part of the goal above.')
            . "\n\nWork thoroughly, then write one complete, self-contained result for the rest of the team to build on.";
    }

    /** Called at the end of a worker job's process: trigger finalisation once every worker is finished. */
    public function onWorkerDone(int $jobId): void
    {
        $j = $this->app->store->job($jobId);
        $did = (int)($j['directive'] ?? 0);
        if (!$did) return;
        foreach ($this->app->store->directiveWorkers($did) as $w)
            if (in_array($w['status'], ['queued', 'running'], true)) return; // not the last worker yet
        $this->finalize($did);
    }

    /** Aggregate the workers' results once, record the anchor per role, and relay the outcome to the chat. */
    private function finalize(int $did): void
    {
        if (!$this->app->store->claimDirective($did)) return; // another finishing worker got here first
        $d = $this->app->store->directive($did);
        $workers = $this->app->store->directiveWorkers($did);
        $cost = 0.0; $parts = [];
        foreach ($workers as $w) {
            $cost += (float)$w['cost_usd'];
            if ($w['role'] !== '') $this->app->assessor()->record($w['role'], null, (float)$w['cost_usd'], null, '', "directive #$did");
            $head = $w['status'] === 'done' ? '' : " _({$w['status']})_";
            $parts[] = "### {$w['role']}$head\n" . trim((string)($w['result'] ?? '(no result)'));
        }
        $done = array_sum(array_map(fn($w) => (int)($w['status'] === 'done'), $workers));
        $aggregate = "Directive \"{$d['title']}\" — goal: {$d['goal']}\n\n$done/" . count($workers) . " workers succeeded.\n\n" . implode("\n\n", $parts);
        $this->app->store->updateDirective($did, ['status' => 'done', 'result' => $aggregate, 'cost_usd' => $cost, 'finished' => time()]);
        $this->app->jobId = null; // relaying is the chat's work
        $how = 'A directive (a team of workers) has finished. Relay the combined result to the user concisely, say what each role produced and flag anything that needs their decision; '
            . 'then ask whether it met the bar — if they accept or reject it, call directive_rate so the roles learn from it.';
        $reply = $this->app->agent()->chat('job', "[Directive #$did \"{$d['title']}\" finished. Its combined result follows. $how]\n\n$aggregate", 'standard', [], null, (int)$d['space']);
        $this->app->notifier()->notify("Directive: {$d['title']}", $reply, (int)$d['space']);
    }

    /** A text status of one directive and its workers, for directive_status. */
    public function status(int $did): string
    {
        $d = $this->app->store->directive($did);
        if (!$d) return "error: no directive #$did.";
        $rows = array_map(fn($w) => sprintf('  - %s · %s · %s · $%.3f%s', $w['role'] ?: $w['title'], $w['tier'], $w['status'], (float)$w['cost_usd'], $w['status'] === 'failed' ? ' — ' . mb_substr((string)$w['result'], 0, 80) : ''),
            $this->app->store->directiveWorkers($did));
        return sprintf("Directive #%d \"%s\" (P%d) — %s · $%.3f total\n%s", $did, $d['title'], $d['priority'], $d['status'], (float)$d['cost_usd'], implode("\n", $rows) ?: '  (no workers)');
    }

    /** Recent directives in this space, one line each. */
    public function list(): string
    {
        $rows = array_map(function ($d) {
            $w = $this->app->store->directiveWorkers((int)$d['id']);
            $run = array_sum(array_map(fn($x) => (int)in_array($x['status'], ['queued', 'running'], true), $w));
            return sprintf('#%d "%s" (P%d) — %s · %d worker(s)%s · $%.3f', $d['id'], $d['title'], $d['priority'], $d['status'], count($w), $run ? ", $run running" : '', (float)$d['cost_usd']);
        }, $this->app->store->directives(15, (int)$this->app->space));
        return $rows ? implode("\n", $rows) : 'no directives in this space yet.';
    }

    /** Structured directives + their workers for the Activity view (directives shown as rows, workers nested). */
    public function board(?int $space = null): array
    {
        $out = [];
        foreach ($this->app->store->directives(20, $space) as $d) {
            $workers = array_map(fn($w) => [
                'id' => (int)$w['id'], 'role' => $w['role'] ?: $w['title'], 'tier' => $w['tier'], 'status' => $w['status'],
                'cost' => (float)$w['cost_usd'], 'note' => $w['status'] === 'failed' ? mb_substr((string)$w['result'], 0, 120) : '',
            ], $this->app->store->directiveWorkers((int)$d['id']));
            $out[] = [
                'id' => (int)$d['id'], 'title' => $d['title'], 'goal' => $d['goal'], 'priority' => (int)$d['priority'],
                'status' => $d['status'], 'cost' => (float)$d['cost_usd'], 'created' => (int)$d['ts'],
                'finished' => $d['finished'] ? (int)$d['finished'] : null, 'result' => (string)($d['result'] ?? ''), 'workers' => $workers,
            ];
        }
        return $out;
    }

    /** The few still-running directives, with how many of their workers are live — cheap enough for the status poll. */
    public function active(?int $space = null): array
    {
        $rows = $this->app->store->q("SELECT id, title, priority FROM directives WHERE status IN ('running','finalizing')" . ($space !== null ? ' AND space = ?' : '') . ' ORDER BY priority, id DESC LIMIT 10', $space !== null ? [$space] : [])->fetchAll();
        return array_map(function ($d) {
            $running = 0; foreach ($this->app->store->directiveWorkers((int)$d['id']) as $w) $running += (int)in_array($w['status'], ['queued', 'running'], true);
            return ['id' => (int)$d['id'], 'title' => $d['title'], 'priority' => (int)$d['priority'], 'running' => $running];
        }, $rows);
    }

    /** Steer a directive: reprioritise, or cancel it (and its unfinished workers). */
    public function steer(int $did, string $action, int $priority = 0): string
    {
        $d = $this->app->store->directive($did);
        if (!$d) return "error: no directive #$did.";
        if ($action === 'prioritize') {
            $this->app->store->updateDirective($did, ['priority' => max(1, min(3, $priority ?: (int)$d['priority']))]);
            return "directive #$did is now P" . max(1, min(3, $priority ?: (int)$d['priority'])) . '.';
        }
        if ($action === 'cancel') {
            foreach ($this->app->store->directiveWorkers($did) as $w) if (in_array($w['status'], ['queued', 'running'], true)) $this->app->jobs()->cancel((int)$w['id']);
            $this->app->store->updateDirective($did, ['status' => 'cancelled', 'finished' => time()]);
            return "directive #$did cancelled; its unfinished workers were stopped.";
        }
        return "error: action must be 'prioritize' or 'cancel'.";
    }

    /** The user accepted or rejected a directive's output: set the anchor on its workers' most recent role runs. */
    public function rate(int $did, bool $accepted): string
    {
        $d = $this->app->store->directive($did);
        if (!$d) return "error: no directive #$did.";
        $n = 0;
        foreach ($this->app->store->directiveWorkers($did) as $w) {
            if ($w['role'] === '') continue;
            $n += $this->app->store->q("UPDATE role_runs SET accepted = ? WHERE id = (SELECT MAX(id) FROM role_runs WHERE role = ? AND accepted IS NULL)",
                [(int)$accepted, $w['role']])->rowCount();
        }
        return "recorded the user's " . ($accepted ? 'acceptance' : 'rejection') . " of directive #$did on $n role run(s).";
    }
}
