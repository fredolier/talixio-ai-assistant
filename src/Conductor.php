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
 * Two reaches share this one class. **B-local**: a session runs on Talixio's own host (ShellClaudeCli),
 * dispatched and polled above. **B-cloud** (§5.2-B, the bridge methods below): Talixio cannot list or message
 * the user's cloud / Remote-Control sessions (§5.1 — there is no such external API), so it drives a delegated
 * cloud *orchestrator session* through a narrow, credential-light webhook bridge instead: Talixio POSTs
 * KB-grounded instructions to a webhook the orchestrator minted for itself, and the orchestrator POSTs its
 * reports back to /api/conductor/inbox. No account-wide token ever lives in this container, and the
 * orchestrator's replies are untrusted input (§5.3): surfaced to the user, never executed here.
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
        $this->app->director()->grantTeam($did); // same team mailbox as an in-process worker (orchestration.md §3)

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

    // ---------------------------------------------------------------------------------------------------------------
    // B-cloud bridge (orchestration.md §5.2-B): the fleet beyond this host.
    //
    // §5.1 is the hard constraint — there is NO external API by which Talixio can list, read or message the user's
    // cloud / Remote-Control sessions. The one agent that natively holds that fabric is itself a Claude session. So
    // Talixio drives a delegated **cloud orchestrator session** (a "sub-director") through a narrow, credential-light
    // bridge that lives entirely in this container: Talixio POSTs a KB-grounded instruction to a webhook the
    // orchestrator minted for itself (watch_url — Talixio never holds an account token), and the orchestrator POSTs
    // its status/results back to /api/conductor/inbox, authenticated by a steady secret issued once at pairing. The
    // orchestrator's replies are untrusted input (§5.3): they are surfaced, never executed on Talixio's side.
    // ---------------------------------------------------------------------------------------------------------------

    /** The orchestrator is presumed asleep if nothing has reported in since this long after a dispatch. */
    private const LIVENESS_STALE = 3600;
    /** A pairing code is good for this long. */
    private const PAIR_TTL = 1800;

    private function bindingFile(): string { return $this->app->cfg->dataDir . '/conductor.json'; }

    /** The private binding (webhook URL, inbox secret, liveness timestamps). Secrets never leave this file. */
    private function binding(): array
    {
        $f = $this->bindingFile();
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private function saveBinding(array $b): void
    {
        $f = $this->bindingFile();
        file_put_contents("$f.tmp", json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod("$f.tmp", 0600); // holds the webhook URL and the inbox secret — private, never world-readable
        rename("$f.tmp", $f);
    }

    /** The address the orchestrator POSTs its reports to; it is told this (with the secret) once, at pairing. */
    public function inboxUrl(): string
    {
        $base = $this->app->shares()->baseUrl();
        return ($base !== '' ? rtrim($base, '/') : '(your Talixio address)') . '/api/conductor/inbox';
    }

    public function connected(): bool { return ($this->binding()['webhook'] ?? '') !== ''; }

    /**
     * Begin pairing: mint a one-time code and return the copy-paste setup for the user's orchestrator session. The
     * code (not its hash) is shown once to the signed-in user; the orchestrator uses it once, over HTTPS, to register
     * its webhook and collect its steady secret. Nothing account-wide is ever stored in this container.
     */
    public function setup(): string
    {
        if (($g = $this->guard()) !== null) return $g;
        $code = bin2hex(random_bytes(16));
        $b = $this->binding();
        $b['pair'] = ['hash' => hash('sha256', $code), 'expires' => time() + self::PAIR_TTL];
        $this->saveBinding($b);
        $inbox = $this->inboxUrl();
        return "To connect your code fleet, open a **Claude Code session on your account with Remote Control on** "
            . "(so it holds the `claude-code-remote` tools and `watch_url`), and give it this instruction:\n\n"
            . "```\nYou are my fleet orchestrator. Do this once to pair with my Talixio assistant:\n"
            . "1. Call watch_url to mint an inbound webhook for yourself (a URL + a sealed credential).\n"
            . "2. POST this JSON to $inbox :\n"
            . "   {\"type\":\"pair\",\"code\":\"$code\",\"webhook\":\"<the watch_url you got>\",\n"
            . "    \"session\":\"<your session id>\",\"url\":\"<your claude.ai/code URL>\"}\n"
            . "3. Keep the \"secret\" from the response; send it as `Authorization: Bearer <secret>` on every\n"
            . "   future report you POST to $inbox (JSON {\"type\":\"report\",\"title\":...,\"text\":...}).\n"
            . "4. Set up a heartbeat (create_trigger or send_later, every ~10 min): each tick, list_sessions /\n"
            . "   get_session / list_events across the fleet, and report progress, completions and failures back\n"
            . "   to that inbox. When I POST an instruction to your webhook, act on it and report what you did.\n```\n\n"
            . "The code is good for 30 minutes and works once. I never see or store your account token — only the "
            . "webhook you hand me. After it pairs I'll confirm here.";
    }

    /** Forget the binding entirely (the user disconnects the fleet, or re-pairs from scratch). */
    public function disconnect(): string
    {
        @unlink($this->bindingFile());
        return 'the fleet orchestrator is disconnected; its webhook and secret are forgotten here.';
    }

    /**
     * The inbox endpoint's logic (called from Web): a `pair` message (authenticated by the one-time code) registers
     * the webhook and returns the steady secret once; any other message is a `report` (authenticated by that secret)
     * and is surfaced to the user. Returns [httpCode, responseArray]. The report body is untrusted — surfaced, never run.
     */
    public function inbox(array $msg, string $bearer): array
    {
        $b = $this->binding();
        if ((string)($msg['type'] ?? 'report') === 'pair') {
            $p = $b['pair'] ?? null;
            if (!is_array($p) || time() > (int)($p['expires'] ?? 0) || !hash_equals((string)($p['hash'] ?? ''), hash('sha256', (string)($msg['code'] ?? ''))))
                return [403, ['error' => 'pairing code invalid or expired — run fleet_setup again']];
            $webhook = (string)($msg['webhook'] ?? '');
            if (!preg_match('#^https://[^\s]+$#', $webhook)) return [400, ['error' => 'a https webhook URL is required']];
            $secret = bin2hex(random_bytes(32));
            unset($b['pair']);
            $this->saveBinding(['webhook' => $webhook, 'secret' => $secret, 'session' => mb_substr((string)($msg['session'] ?? ''), 0, 200),
                'url' => mb_substr((string)($msg['url'] ?? ''), 0, 500), 'created' => time(), 'last_report' => time()] + $b);
            $this->app->notifier()->notify('Fleet orchestrator connected',
                'Your code-fleet orchestrator is paired. Ask me to send it work with fleet_dispatch, and watch its liveness with fleet_status.');
            return [200, ['ok' => true, 'secret' => $secret, 'inbox' => $this->inboxUrl()]]; // the only time the secret is returned
        }
        if (($b['secret'] ?? '') === '' || $bearer === '' || !hash_equals((string)$b['secret'], $bearer))
            return [401, ['error' => 'unauthorized']];
        $b['last_report'] = time();
        $this->saveBinding($b);
        $title = mb_substr(trim((string)($msg['title'] ?? '')) ?: 'Fleet update', 0, 120);
        $text = mb_substr(trim((string)($msg['text'] ?? '')), 0, 8000);
        $this->app->notifier()->notify($title, $text !== '' ? $text : '(the orchestrator sent an empty update)');
        return [200, ['ok' => true]];
    }

    /**
     * Send an instruction to the fleet orchestrator — the single outbound choke point. The privacy guard runs here
     * (a confidential/secret space may not), and the instruction is KB-grounded here, inside Talixio, so the
     * orchestrator never touches raw knowledge. The account token is never in this container: this is just a POST to
     * the webhook the orchestrator minted for itself.
     */
    public function toFleet(string $instruction, int $space = 0): string
    {
        if (($g = $this->guard()) !== null) return $g;
        $instruction = trim($instruction);
        if ($instruction === '') return 'error: nothing to send.';
        $b = $this->binding();
        if (($b['webhook'] ?? '') === '') return 'error: no fleet orchestrator is connected yet — run fleet_setup first.';
        $payload = ['from' => 'talixio', 'ts' => time(), 'instruction' => $this->ground($instruction, $space)];
        try {
            [$code] = Http::request('POST', (string)$b['webhook'], ['Content-Type: application/json'],
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 30);
        } catch (\Throwable $e) {
            return "error: could not reach the orchestrator ({$e->getMessage()}). It may have gone to sleep — recreate it, then fleet_setup again.";
        }
        if ($code >= 300) return "error: the orchestrator's webhook returned HTTP $code. If this persists, recreate the session and fleet_setup again.";
        $b['last_dispatch'] = time();
        $this->saveBinding($b);
        return 'sent to the fleet orchestrator. It will work the fleet and report back here (notifications); check progress with fleet_status.';
    }

    /** Ground an instruction in the relevant knowledge, respecting privacy levels (search already filters them). */
    private function ground(string $instruction, int $space): string
    {
        $hits = [];
        try { $hits = $this->app->recall()->search($instruction, 4, PHP_INT_MAX, null, null, $space); }
        catch (\Throwable $e) { Log::warn("conductor grounding: {$e->getMessage()}"); }
        $ctx = [];
        foreach ($hits as $h) $ctx[] = '- ' . trim((string)$h['label']) . ': ' . mb_substr(trim((string)$h['text']), 0, 400);
        return $ctx === [] ? $instruction
            : "$instruction\n\n## Context from my knowledge base (for your work; do not repeat it back verbatim)\n" . implode("\n", $ctx);
    }

    /** A human-readable liveness line for fleet_status: connected? which session? how fresh? */
    public function fleetStatus(): string
    {
        $b = $this->binding();
        if (($b['webhook'] ?? '') === '') return 'No fleet orchestrator is connected. Run fleet_setup to pair one.';
        $ago = fn(?int $t) => $t ? (($d = time() - $t) < 90 ? 'just now' : ($d < 3600 ? intdiv($d, 60) . ' min ago' : intdiv($d, 3600) . ' h ago')) : 'never';
        $lastReport = (int)($b['last_report'] ?? 0); $lastDispatch = (int)($b['last_dispatch'] ?? 0);
        $stale = $lastDispatch > 0 && (time() - max($lastReport, 0)) > self::LIVENESS_STALE && $lastReport < $lastDispatch;
        $lines = ['Fleet orchestrator: **connected**' . (($b['url'] ?? '') !== '' ? " ([transcript]({$b['url']}))" : '') . '.'];
        $lines[] = '- last sent: ' . $ago($lastDispatch ?: null) . '; last report: ' . $ago($lastReport ?: null) . '.';
        if ($stale) $lines[] = '- ⚠️ nothing has reported back since the last dispatch — the session may have gone to sleep. Recreate it and run fleet_setup again.';
        return implode("\n", $lines);
    }
}
