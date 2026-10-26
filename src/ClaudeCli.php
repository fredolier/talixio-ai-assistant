<?php
declare(strict_types=1);
namespace Talixio;

/**
 * A delegated Claude Code session — the agent factory's **Tier-D** worker environment
 * (docs/design/orchestration.md §5.4). The conductor starts one per role it dispatches and
 * reads its progress back; the session has its own repo, git and shell for hours of work, so
 * it does not run through the in-process jobs engine.
 *
 * This is the narrow surface the conductor needs. The real implementation drives the local
 * `claude` CLI (B-local — a background session on Talixio's host, read back from its log);
 * FakeClaudeCli drives tests. A handle is an opaque string the conductor stores on the worker
 * job (jobs.session) and passes back to poll/stop — never parsed by the conductor.
 */
interface ClaudeCli
{
    /** Start a detached session seeded with $prompt, running the model of $tier in $cwd. Returns its handle. */
    public function start(string $prompt, Tier $tier, string $cwd): string;

    /**
     * How one session is doing, by its handle:
     * ['done'=>bool, 'ok'=>bool, 'result'=>string, 'cost'=>float]. While running, done is false.
     */
    public function poll(string $handle): array;

    /** Stop a running session (the directive was cancelled). Best effort; safe to call on a finished one. */
    public function stop(string $handle): void;
}
