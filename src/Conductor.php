<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The session conductor — the agent factory's **Tier-D** fleet tier (docs/design/orchestration.md §5.4).
 *
 * It dispatches a ROLE as a delegated Claude Code session (a real repo/git/shell for hours) instead of an
 * in-process worker job, and reads its progress back. It is **not** a second system: it reuses the director's
 * own role path so every agent in the fleet is homogeneous. A dispatch resolves the role and seeds the prompt
 * with `Director::plan`/`Director::seed` (byte-identical to an in-process worker), creates an ordinary
 * directive with a Tier-D worker job, and starts the session through ClaudeCli. When the session finishes,
 * poll() records the outcome on that job and hands off to `Director::onWorkerDone` — the same finalisation
 * that aggregates the directive, relays it to the chat and records the anchor per role for the assessor. So a
 * code agent is measured and improved exactly like any other role, and there is no separate board or budget.
 *
 * This is the B-local slice: sessions run on Talixio's host (ShellClaudeCli). Whole-account cloud reach and
 * the credential-light webhook bridge (B-cloud, §5.2-B) are a later slice.
 */
final class Conductor
{
    public function __construct(private App $app, private ClaudeCli $cli) {}

    /**
     * Dispatch one role toward a goal as a Tier-D session. Returns a status line, or an error string
     * (no such role, a non-internal space, or budget exhausted) — the same shape the director's tools return.
     */
    public function dispatch(string $role, string $task, string $goal, string $tier = '', int $space = 0): string
    {
        if (($guard = $this->guard()) !== null) return $guard;
        if (($state = $this->app->budget()->status()['state']) !== 'ok') return "error: budget is $state — not starting a code session now.";

        $plans = $this->app->director()->plan([['role' => $role, 'task' => $task, 'tier' => $tier]]);
        if (is_string($plans)) return $plans; // no such role → search with role_find, create with role_save
        $p = $plans[0];
        $prompt = Director::seed($p['role'], $p['brief'], $goal, $p['task']);

        $did = $this->app->store->addDirective(mb_substr(trim($goal) ?: $p['role'], 0, 60), trim($goal), 2, $space);
        $jid = $this->app->store->addJob("{$p['role']}: " . mb_substr($p['task'] ?: $goal, 0, 50), $prompt, $p['tier'],
            $this->app->jobs()->cap(null), 'conductor', null, $space, [], '', null, '', $did, $p['role']);

        $cwd = $this->app->cfg->dataDir . "/conductor/$jid";
        $handle = $this->cli->start($prompt, $this->app->cfg->tier($p['tier']), $cwd);
        $this->app->store->updateJob($jid, ['status' => 'running', 'started' => time(), 'session' => $handle]);

        return "code session #$jid started: role **{$p['role']}** ({$p['tier']}) on directive #$did. It runs in the background; "
            . "I'll report when it finishes. Track it with directive_status $did.";
    }

    /** Worker-tick housekeeping: advance every live Tier-D session — stop cancelled ones, finish done ones. */
    public function poll(): void
    {
        foreach ($this->sessions() as $j) {
            $jid = (int)$j['id'];
            if ($j['status'] === 'cancelled') { // steered to cancel: stop the session and stop tracking it
                $this->cli->stop((string)$j['session']);
                $this->app->store->updateJob($jid, ['session' => '']);
                continue;
            }
            $s = $this->cli->poll((string)$j['session']);
            if (!$s['done']) continue;
            $this->app->store->updateJob($jid, [
                'status' => $s['ok'] ? 'done' : 'failed',
                'result' => $s['result'] !== '' ? $s['result'] : ($s['ok'] ? '(no result)' : 'the session failed'),
                'cost_usd' => (float)$s['cost'], 'session' => '', 'finished' => time(),
            ]);
            $this->app->director()->onWorkerDone($jid); // same finalisation as an in-process worker: aggregate, relay, record the anchor
        }
    }

    /** Tier-D worker jobs still being tracked (a live session handle): running to be polled, cancelled to be stopped. */
    private function sessions(): array
    {
        return $this->app->store->q("SELECT * FROM jobs WHERE kind='conductor' AND session != '' AND status IN ('running','cancelled') ORDER BY id")->fetchAll();
    }

    /** True once any Tier-D session is live, so the worker tick only polls when the conductor is in use. */
    public function busy(): bool { return $this->sessions() !== []; }

    /**
     * The privacy choke point (§5.4): a confidential/secret space cannot delegate a code session — its seed
     * prompt would leave the assistant for an outside runtime. Null when the current space may dispatch.
     */
    private function guard(): ?string
    {
        $level = $this->app->privacy()->effective();
        if ($level === 'internal') return null;
        Privacy::blocked('Start a code session');
        return "error: not started: this space (or what it read) is at the " . ucfirst($level)
            . " level, and a delegated code session runs outside the assistant. Dispatch from an internal space instead.";
    }
}
