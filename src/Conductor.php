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

    /**
     * Read-modify-write the binding under an exclusive lock, so the concurrent paths that touch the poll queue — a
     * report coming in, an instruction being queued, the orchestrator draining its queue — cannot lose or resurrect
     * each other's changes. $fn receives the fresh binding and returns [newBinding, result]; result is returned.
     */
    private function lockedUpdate(callable $fn)
    {
        $fh = @fopen($this->bindingFile() . '.lock', 'c');
        if ($fh) flock($fh, LOCK_EX);
        try {
            [$b, $ret] = $fn($this->binding());
            $this->saveBinding($b);
            return $ret;
        } finally {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
        }
    }

    /** The address the orchestrator POSTs its reports to; it is told this (with the secret) once, at pairing. */
    public function inboxUrl(): string
    {
        $base = $this->app->shares()->baseUrl();
        return ($base !== '' ? rtrim($base, '/') : '(your Talixio address)') . '/api/conductor/inbox';
    }

    /** The address a poll-mode orchestrator GETs (Bearer secret) to pull the instructions Talixio has queued for it. */
    public function pullUrl(): string
    {
        $base = $this->app->shares()->baseUrl();
        return ($base !== '' ? rtrim($base, '/') : '(your Talixio address)') . '/api/conductor/pull';
    }

    /** Push mode (the orchestrator minted a webhook) or poll mode (it pulls a queue); '' when not paired. */
    private function mode(): string
    {
        $b = $this->binding();
        if (($b['secret'] ?? '') === '') return '';
        return ($b['webhook'] ?? '') !== '' ? 'push' : (($b['mode'] ?? '') === 'poll' ? 'poll' : '');
    }

    public function connected(): bool { return $this->mode() !== ''; }

    /**
     * A secret-safe snapshot for the settings UI: never the webhook URL or the inbox secret, only whether a binding
     * exists, the orchestrator's self-reported session id / transcript link, and the liveness timestamps.
     */
    public function statusInfo(): array
    {
        $b = $this->binding();
        $agents = array_values($b['agents'] ?? []);
        $machines = count(array_unique(array_filter(array_map(fn($a) => (string)($a['machine'] ?? ''), $agents))));
        return [
            'connected' => $this->connected(),
            'mode' => $this->mode(), // 'push' (webhook) or 'poll' (the orchestrator pulls a queue); '' when not paired
            'inbox' => $this->inboxUrl(),
            'session' => (string)($b['session'] ?? ''),
            'url' => (string)($b['url'] ?? ''),
            'lastReport' => (int)($b['last_report'] ?? 0),
            'lastPoll' => (int)($b['last_poll'] ?? 0),
            'queued' => count($b['queue'] ?? []),
            'lastDispatch' => (int)($b['last_dispatch'] ?? 0),
            'agents' => $agents,
            'agentCount' => count($agents),
            'waiting' => count(array_filter($agents, fn($a) => !empty($a['waiting']))),
            'machines' => $machines,
            'agentsAt' => (int)($b['agents_at'] ?? 0),
            'summary' => $this->fleetStatus(),
            'notify' => self::modeOf($b),             // how reports surface: inapp | push | off
            'unread' => (int)($b['unread'] ?? 0),     // reports since the user last opened Votre pilote autonome
            'reports' => array_values($b['reports'] ?? []), // the recent-reports feed (the "details")
        ];
    }

    /**
     * Begin pairing: mint a one-time code and return the copy-paste setup for the user's orchestrator session. The
     * code (not its hash) is shown once to the signed-in user; the orchestrator uses it once, over HTTPS, to register
     * its webhook and collect its steady secret. Nothing account-wide is ever stored in this container.
     */
    public function setup(): string
    {
        // Pairing sends nothing outward — it only mints a one-time code and stores the webhook the orchestrator hands
        // back — so it is not gated by the privacy guard. The outbound choke (toFleet) is where confidential/secret
        // spaces are refused; connecting a fleet from any space is safe.
        $code = bin2hex(random_bytes(16));
        $b = $this->binding();
        $b['pair'] = ['hash' => hash('sha256', $code), 'expires' => time() + self::PAIR_TTL];
        $this->saveBinding($b);
        $inbox = $this->inboxUrl();
        $pull = $this->pullUrl();
        return "To connect your code fleet, start a **Claude Code session signed in to your account** (so it can see "
            . "your sessions across machines — ListAgents / SendMessage) and keep it running. Pair it one of two ways — "
            . "whichever your session supports — and give it this once:\n\n"
            . "```\nYou are my fleet orchestrator. Pair once with my Talixio assistant, then keep working my fleet.\n\n"
            . "PAIRING — pick the transport your session can support:\n"
            . " • PUSH (a cloud / Remote-Control session that can mint an inbound webhook with watch_url):\n"
            . "   mint the webhook, then POST to $inbox :\n"
            . "   {\"type\":\"pair\",\"code\":\"$code\",\"webhook\":\"<your watch_url>\",\n"
            . "    \"session\":\"<your session id>\",\"url\":\"<your claude.ai/code URL, if any>\"}\n"
            . " • POLL (a local session with no watch_url): POST to $inbox :\n"
            . "   {\"type\":\"pair\",\"code\":\"$code\",\"mode\":\"poll\",\n"
            . "    \"session\":\"<your session id>\",\"url\":\"<your claude.ai/code URL, if any>\"}\n\n"
            . "Keep the \"secret\" from the response; send it as `Authorization: Bearer <secret>` on everything below.\n\n"
            . "RUNNING — keep yourself warm (~10 min: create_trigger / send_later, or a /loop). Each tick:\n"
            . " 1. Enumerate my sessions across machines and read their progress.\n"
            . " 2. Get my pending instructions. If you paired with POLL, GET $pull\n"
            . "    with the Bearer secret → {\"instructions\":[{\"instruction\":...,\"agent\"?:...}, ...]} (it empties the\n"
            . "    queue, so act on every item). If you paired with PUSH, I deliver the same payloads to your webhook\n"
            . "    instead — act on each when it arrives.\n"
            . " 3. POST progress to $inbox as {\"type\":\"report\",\"title\":...,\"text\":...}, with a structured roster\n"
            . "    so my cockpit can list the fleet:\n"
            . "    \"agents\":[{\"id\":\"mba-m3.local\",\"name\":\"mba-m3.local\",\"task\":\"dossier conseil\",\n"
            . "    \"state\":\"active\",\"machine\":\"mba-m3.local\",\"ts\":<unix>}] (state: active|busy|waiting|idle|done|error).\n"
            . "    A routine keep-warm tick with no news MUST be silent: either omit \"text\" or add \"tick\":true, so I\n"
            . "    refresh the roster without notifying the user. Send \"text\" only when there is something to report.\n"
            . " 3b. WHEN A SESSION IS BLOCKED, I need the TEXT of what it is waiting for — its state alone tells me nothing.\n"
            . "    Convention: any blocked session writes its question to ./.claude/talixio-code/inbox/QUESTION-<session>.md\n"
            . "    (create the folder) with a fixed template: type (my own ladder, e.g. T1–T7), question, options, cost of a\n"
            . "    wrong choice. At every tick, read those files (a plain cat) and put each one VERBATIM, without summarising or\n"
            . "    interpreting, in that agent's roster entry: \"state\":\"waiting\",\"waiting\":{\"kind\":\"decision\",\n"
            . "    \"type\":\"T3\",\"question\":\"...\",\"options\":[\"A\",\"B\"],\"cost\":\"...\"}. Use kind \"approval\" for a session\n"
            . "    stuck on a click or a permission (e.g. a revoked token) that only I can give in that session, and kind\n"
            . "    \"instruction\" when it just needs you to tell it what to do next (you may write to it yourself). If you cannot\n"
            . "    find the question, still send \"waiting\" with what you do know. Never tell me nothing is waiting while a\n"
            . "    session is blocked: my cockpit lists every agent that carries a \"waiting\" entry as needing me.\n"
            . " 4. When an instruction carries an \"agent\" field, route it to that one session and report its reply\n"
            . "    back with the same \"agent\" set and the reply in \"text\" — the two-voice thread the user talks to.\n```\n\n"
            . "The code is good for 30 minutes and works once. I never see or store your account token — only the "
            . "webhook you hand me (push), or nothing at all (poll). After it pairs I'll confirm here.";
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
            $poll = (string)($msg['mode'] ?? '') === 'poll' || !empty($msg['poll']);
            if (!$poll && !preg_match('#^https://[^\s]+$#', $webhook))
                return [400, ['error' => 'a https webhook URL is required, or set "mode":"poll" to pull instructions instead']];
            $secret = bin2hex(random_bytes(32));
            // A fresh binding for this pairing; keep only the display-only roster/threads from any prior one, and make
            // sure a mode switch (push→poll or back) never leaves a stale webhook or queue behind.
            $keep = array_intersect_key($b, array_flip(['agents', 'agents_at', 'threads']));
            $base = ['secret' => $secret, 'session' => mb_substr((string)($msg['session'] ?? ''), 0, 200),
                'url' => mb_substr((string)($msg['url'] ?? ''), 0, 500), 'created' => time(), 'last_report' => time()];
            $base += $poll ? ['mode' => 'poll', 'queue' => []] : ['webhook' => $webhook];
            $this->saveBinding($base + $keep);
            $this->app->notifier()->notify('Fleet orchestrator connected',
                'Your code-fleet orchestrator is paired' . ($poll ? ' (poll mode)' : '') . '. Ask me to send it work with fleet_dispatch, and watch its liveness with fleet_status.');
            // the only time the secret is returned; a poll orchestrator also learns where to pull from
            return [200, ['ok' => true, 'secret' => $secret, 'inbox' => $this->inboxUrl(), 'mode' => $poll ? 'poll' : 'push'] + ($poll ? ['pull' => $this->pullUrl()] : [])];
        }
        if (($b['secret'] ?? '') === '' || $bearer === '' || !hash_equals((string)$b['secret'], $bearer))
            return [401, ['error' => 'unauthorized']];
        $title = mb_substr(trim((string)($msg['title'] ?? '')) ?: 'Fleet update', 0, 120);
        $text = mb_substr(trim((string)($msg['text'] ?? '')), 0, 8000);
        // The orchestrator may carry a structured agent roster so the cockpit can list the fleet. Display data only —
        // bounded and surfaced, never executed here (orchestration.md §5.3).
        $agents = isset($msg['agents']) && is_array($msg['agents']) ? $this->sanitizeAgents($msg['agents']) : null;
        // A report tagged with an `agent` is that one agent's reply in its two-voice thread (the "Discuter" drawer).
        $agent = mb_substr(trim((string)($msg['agent'] ?? '')), 0, 80);
        // A keep-warm tick (a roster/liveness refresh, with no news) must NOT raise a notification — the orchestrator
        // pings roughly every ~10 min to stay warm, and notifying each time spams the user. We notify only on a report
        // that carries text, isn't explicitly flagged as a silent/liveness tick, AND isn't a verbatim repeat of the last
        // line we notified. The flag is a courtesy the orchestrator may forget; the repeat-check is the backstop, so a
        // chatty orchestrator re-sending the same status every tick goes quiet after the first and only genuinely new
        // text breaks through. The check-and-mark lives inside the lock so two concurrent reports can't both notify.
        $silent = !empty($msg['tick']) || !empty($msg['silent']) || in_array((string)($msg['kind'] ?? ''), ['tick', 'liveness', 'heartbeat', 'keepalive'], true);
        // How a fresh report surfaces is the user's choice (fleet_notify, default 'inapp' — see orchestration.md §5.4):
        //   off   — surfaced nowhere: no feed line, no badge, no push.
        //   inapp — a feed line in "Votre pilote autonome" and a badge count on the Pilotage menu; no push/e-mail.
        //   push  — the same feed line and badge, plus the push+e-mail notification (the pre-1.12 behaviour).
        $push = $this->lockedUpdate(function (array $b) use ($agents, $agent, &$title, $text, $silent) { // locked: never clobber a concurrent queue drain
            $b['last_report'] = time();
            $asks = self::newQuestions($b['agents'] ?? [], $agents); // a session that now waits for the user is news, even on a keep-warm tick
            if ($agents !== null) { $b['agents'] = $agents; $b['agents_at'] = time(); }
            if ($agent !== '' && $text !== '') $b = $this->appendThread($b, $agent, 'agent', $text);
            if ($asks !== '') { $text = trim($text . "\n\n" . $asks); $silent = false; $title = 'An agent needs you'; }
            $fresh = $text !== '' && !$silent && $text !== (string)($b['last_notified'] ?? '');
            if (!$fresh) return [$b, false];
            $b['last_notified'] = $text; // only a notified line updates the mark; silent ticks never poison it
            $mode = self::modeOf($b);
            if ($mode !== 'off') {       // record the report for the in-app feed + badge
                $feed = $b['reports'] ?? [];
                $feed[] = ['ts' => time(), 'title' => $title, 'text' => $text, 'agent' => $agent];
                if (count($feed) > self::REPORT_CAP) $feed = array_slice($feed, -self::REPORT_CAP);
                $b['reports'] = array_values($feed);
                $b['unread'] = (int)($b['unread'] ?? 0) + 1;
            }
            return [$b, $mode === 'push'];
        });
        if ($push) $this->app->notifier()->notify($title, $text);
        return [200, ['ok' => true]];
    }

    private const AGENT_CAP = 50;
    private const THREAD_CAP = 40;
    /** How many recent fleet reports the in-app feed keeps (the "details" in Votre pilote autonome). */
    private const REPORT_CAP = 30;

    /** How fleet reports surface: 'inapp' (badge + feed, default), 'push' (badge + feed + push/e-mail) or 'off'. */
    private static function modeOf(array $b): string
    {
        $m = (string)($b['fleet_notify'] ?? 'inapp');
        return in_array($m, ['inapp', 'push', 'off'], true) ? $m : 'inapp';
    }
    public function notifyMode(): string { return self::modeOf($this->binding()); }
    public function unread(): int { return (int)($this->binding()['unread'] ?? 0); }
    public function reports(): array { return array_values($this->binding()['reports'] ?? []); }

    /** Set how fleet reports surface (the toggle in Votre pilote autonome). Returns the fresh status snapshot. */
    public function setNotify(string $mode): array
    {
        $mode = in_array($mode, ['inapp', 'push', 'off'], true) ? $mode : 'inapp';
        $this->lockedUpdate(fn(array $b) => [['fleet_notify' => $mode] + $b, null]);
        return $this->statusInfo();
    }

    /** Clear the unread badge (called when the user opens Votre pilote autonome). */
    public function markRead(): array
    {
        $this->lockedUpdate(fn(array $b) => [['unread' => 0] + $b, null]);
        return ['unread' => 0];
    }
    /** How many undelivered instructions a poll-mode orchestrator's queue holds; oldest drop past this. */
    private const QUEUE_CAP = 100;

    /**
     * A poll-mode orchestrator (no webhook) pulls its pending instructions here, authenticated by the steady secret.
     * Draining is atomic, so each instruction is handed out exactly once. Returns [httpCode, responseArray].
     */
    public function pull(string $bearer): array
    {
        $b = $this->binding();
        if (($b['secret'] ?? '') === '' || $bearer === '' || !hash_equals((string)$b['secret'], $bearer))
            return [401, ['error' => 'unauthorized']];
        if ($this->mode() !== 'poll')
            return [409, ['error' => 'this orchestrator is paired for webhook push, not polling']];
        $pending = $this->lockedUpdate(function (array $b) {
            $q = array_values($b['queue'] ?? []);
            $b['queue'] = [];
            $b['last_poll'] = time(); // polling is a liveness signal even before the first report
            return [$b, $q];
        });
        return [200, ['ok' => true, 'instructions' => $pending]];
    }

    /**
     * Hand one instruction payload to the orchestrator: POST it to the webhook (push), or append it to the queue the
     * orchestrator pulls (poll). Returns ['ok'=>bool] with 'error' ('unreachable'|'http'|'not-connected') on failure.
     */
    private function deliver(array $payload): array
    {
        $mode = $this->mode();
        if ($mode === '') return ['ok' => false, 'error' => 'not-connected'];
        if ($mode === 'poll') {
            $this->lockedUpdate(function (array $b) use ($payload) {
                $q = array_values($b['queue'] ?? []);
                $q[] = $payload;
                if (count($q) > self::QUEUE_CAP) $q = array_slice($q, -self::QUEUE_CAP);
                $b['queue'] = $q;
                $b['last_dispatch'] = time();
                return [$b, null];
            });
            return ['ok' => true, 'queued' => true];
        }
        try {
            [$code] = Http::request('POST', (string)$this->binding()['webhook'], ['Content-Type: application/json'],
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 30);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'unreachable', 'detail' => $e->getMessage()];
        }
        if ($code >= 300) return ['ok' => false, 'error' => 'http', 'code' => $code];
        $this->lockedUpdate(fn(array $b) => [['last_dispatch' => time()] + $b, null]);
        return ['ok' => true, 'queued' => false];
    }

    /** Bound and clean the orchestrator's self-reported roster — display data, never executed (orchestration.md §5.3). */
    private function sanitizeAgents(array $raw): array
    {
        $out = [];
        foreach (array_slice(array_values($raw), 0, self::AGENT_CAP) as $a) {
            if (!is_array($a)) continue;
            $id = mb_substr(trim((string)($a['id'] ?? $a['name'] ?? '')), 0, 80);
            if ($id === '') continue;
            $out[] = [
                'id' => $id,
                'name' => mb_substr(trim((string)($a['name'] ?? $id)) ?: $id, 0, 80),
                'task' => mb_substr(trim((string)($a['task'] ?? '')), 0, 160),
                'state' => mb_substr(trim((string)($a['state'] ?? '')), 0, 24),
                'machine' => mb_substr(trim((string)($a['machine'] ?? '')), 0, 80),
                'ts' => (int)($a['ts'] ?? time()),
            ] + (($w = self::sanitizeWaiting($a['waiting'] ?? ($a['question'] ?? null))) !== null ? ['waiting' => $w] : []);
        }
        return $out;
    }

    /**
     * What a blocked session is waiting for, as the orchestrator copied it (the question's own text, verbatim — a
     * session's state alone never says what it asks). Bounded display data: kind decision|approval|instruction (an
     * approval is a click only the user can make in that session), a short free `type` (the user's own ladder, e.g.
     * T1–T7), the question, up to six options, and what a wrong choice costs. A bare string is taken as the question.
     */
    private static function sanitizeWaiting(mixed $w): ?array
    {
        if (is_string($w)) $w = ['question' => $w];
        if (!is_array($w)) return null;
        $q = mb_substr(trim((string)($w['question'] ?? '')), 0, 1500);
        if ($q === '') return null;
        $opts = [];
        foreach ((array)($w['options'] ?? []) as $o) if (is_scalar($o) && ($o = mb_substr(trim((string)$o), 0, 120)) !== '') $opts[] = $o;
        $opts = array_slice($opts, 0, 6);
        $kind = (string)($w['kind'] ?? 'decision');
        return ['kind' => in_array($kind, ['decision', 'approval', 'instruction'], true) ? $kind : 'decision',
            'type' => mb_substr(trim((string)($w['type'] ?? '')), 0, 24), 'question' => $q, 'options' => $opts,
            'cost' => mb_substr(trim((string)($w['cost'] ?? '')), 0, 240)];
    }

    /** One line per agent whose question is new since the previous roster (same session, same text = already seen). */
    private static function newQuestions(array $prev, ?array $now): string
    {
        $seen = [];
        foreach ($prev as $a) if (!empty($a['waiting'])) $seen[$a['id'] . '|' . sha1($a['waiting']['question'])] = true;
        $out = [];
        foreach ($now ?? [] as $a) {
            if (empty($a['waiting']) || isset($seen[$a['id'] . '|' . sha1($a['waiting']['question'])])) continue;
            $out[] = "**{$a['name']}** is waiting for you" . ($a['waiting']['kind'] === 'approval' ? ' (a click in its own session)' : '') . ': ' . mb_substr(trim(preg_replace('/\s+/u', ' ', $a['waiting']['question'])), 0, 300);
        }
        return implode("\n", $out);
    }

    /** Append one turn to an agent's two-voice thread, keeping only the last THREAD_CAP turns. Returns the new binding. */
    private function appendThread(array $b, string $id, string $role, string $text): array
    {
        $t = $b['threads'][$id] ?? [];
        $t[] = ['role' => $role === 'agent' ? 'agent' : 'user', 'text' => mb_substr(trim($text), 0, 4000), 'ts' => time()];
        if (count($t) > self::THREAD_CAP) $t = array_slice($t, -self::THREAD_CAP);
        $b['threads'][$id] = array_values($t);
        return $b;
    }

    /** The stored two-voice thread with one agent (what the cockpit's "Discuter" drawer shows). */
    public function agentThread(string $id): array
    {
        return array_values($this->binding()['threads'][mb_substr(trim($id), 0, 80)] ?? []);
    }

    /**
     * Send one line to a single agent — a targeted dispatch through the same outbound choke as toFleet (the privacy
     * guard runs; the line is KB-grounded here). The orchestrator routes it to that agent and reports the reply back
     * tagged with the same id, which lands in the thread. Returns {ok, message, thread}; never throws.
     */
    public function agentSay(string $id, string $text, int $space = 0): array
    {
        $id = mb_substr(trim($id), 0, 80);
        $text = trim($text);
        $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'thread' => $this->agentThread($id)];
        if ($id === '') return $fail('error: no agent.');
        if ($text === '') return $fail('error: nothing to send.');
        if (($g = $this->guard()) !== null) return $fail($g);
        if (!$this->connected()) return $fail('error: no agents are connected yet — pair your pilote autonome first.');
        $r = $this->deliver(['from' => 'talixio', 'ts' => time(), 'agent' => $id, 'instruction' => $this->ground($text, $space)]);
        if (!$r['ok']) return $fail($r['error'] === 'http'
            ? "error: the orchestrator's webhook returned HTTP {$r['code']}."
            : "error: could not reach your pilote autonome ({$r['detail']}). It may be asleep — recreate it, then re-pair.");
        $this->lockedUpdate(fn(array $b) => [$this->appendThread($b, $id, 'user', $text), null]);
        return ['ok' => true, 'thread' => $this->agentThread($id),
            'message' => !empty($r['queued']) ? 'queued for the agent; it will pick this up on its next poll and its reply will land here.' : 'sent to the agent; its reply will land here.'];
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
        if (!$this->connected()) return 'error: no fleet orchestrator is connected yet — run fleet_setup first.';
        $r = $this->deliver(['from' => 'talixio', 'ts' => time(), 'instruction' => $this->ground($instruction, $space)]);
        if (!$r['ok']) return $r['error'] === 'http'
            ? "error: the orchestrator's webhook returned HTTP {$r['code']}. If this persists, recreate the session and fleet_setup again."
            : "error: could not reach the orchestrator ({$r['detail']}). It may have gone to sleep — recreate it, then fleet_setup again.";
        return !empty($r['queued'])
            ? 'queued for the fleet orchestrator — it will pick this up on its next poll and report back here (notifications); check progress with fleet_status.'
            : 'sent to the fleet orchestrator. It will work the fleet and report back here (notifications); check progress with fleet_status.';
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
        if (!$this->connected()) return 'No fleet orchestrator is connected. Run fleet_setup to pair one.';
        $poll = $this->mode() === 'poll';
        $ago = fn(?int $t) => $t ? (($d = time() - $t) < 90 ? 'just now' : ($d < 3600 ? intdiv($d, 60) . ' min ago' : intdiv($d, 3600) . ' h ago')) : 'never';
        $lastReport = (int)($b['last_report'] ?? 0); $lastDispatch = (int)($b['last_dispatch'] ?? 0); $lastPoll = (int)($b['last_poll'] ?? 0);
        $heard = max($lastReport, $lastPoll); // in poll mode a pull is a liveness signal too, even before the first report
        $queued = count($b['queue'] ?? []);
        $stale = $lastDispatch > 0 && (time() - $heard) > self::LIVENESS_STALE && $heard < $lastDispatch;
        $lines = ['Fleet orchestrator: **connected**' . ($poll ? ' (poll)' : '') . (($b['url'] ?? '') !== '' ? " ([transcript]({$b['url']}))" : '') . '.'];
        $lines[] = '- last sent: ' . $ago($lastDispatch ?: null) . '; last ' . ($poll ? 'poll' : 'report') . ': ' . $ago(($poll ? $heard : $lastReport) ?: null) . '.';
        if ($poll && $queued > 0) $lines[] = "- $queued instruction(s) waiting for the next poll.";
        if ($stale) $lines[] = '- ⚠️ nothing has ' . ($poll ? 'polled' : 'reported') . ' back since the last dispatch — the session may have gone to sleep. Recreate it and run fleet_setup again.';
        return implode("\n", $lines);
    }
}
