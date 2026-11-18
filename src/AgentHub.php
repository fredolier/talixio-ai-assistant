<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The agent board (1.36.0): coding agents (Claude Code first, any tool that can run a command later) report their
 * own events here through a small hook script, word for word and as they happen, and pick up the answers given
 * in Talixio (a reply, allow, deny). No model sits in between: what the board shows is what the agent said, with
 * its real time. The assistant reads the board and can answer through it, like the person (agents_* tools).
 *
 * Machines authenticate with one Bearer token (kv "agents.token"), never with the web session.
 */
final class AgentHub
{
    public const MAX_TEXT = 8000;
    private const AGENTS = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

    public function __construct(private App $app)
    {
        $app->store->db->exec("CREATE TABLE IF NOT EXISTS agent_sessions(id TEXT PRIMARY KEY, agent TEXT NOT NULL, machine TEXT NOT NULL DEFAULT '',
                cwd TEXT NOT NULL DEFAULT '', state TEXT NOT NULL, last_message TEXT NOT NULL DEFAULT '', question TEXT NOT NULL DEFAULT '',
                seq INTEGER NOT NULL DEFAULT 0, started INTEGER NOT NULL, updated INTEGER NOT NULL, polled INTEGER NOT NULL DEFAULT 0,
                listening TEXT NOT NULL DEFAULT '', seen INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS agent_events(id INTEGER PRIMARY KEY, session TEXT NOT NULL, ts INTEGER NOT NULL, who TEXT NOT NULL, kind TEXT NOT NULL, text TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS agent_events_s ON agent_events(session, id);
            CREATE TABLE IF NOT EXISTS agent_commands(id INTEGER PRIMARY KEY, session TEXT NOT NULL, ts INTEGER NOT NULL, kind TEXT NOT NULL, text TEXT NOT NULL,
                by TEXT NOT NULL DEFAULT 'you', delivered INTEGER);
            CREATE INDEX IF NOT EXISTS agent_commands_s ON agent_commands(session, delivered);");
    }

    // ---------- the machine side (Bearer token) ----------

    public function token(bool $renew = false): string
    {
        $t = (string)$this->app->store->get('agents.token');
        if ($t === '' || $renew) { $t = bin2hex(random_bytes(24)); $this->app->store->set('agents.token', $t); }
        return $t;
    }

    public function authorized(string $bearer): bool
    {
        $t = (string)$this->app->store->get('agents.token');
        return $t !== '' && $bearer !== '' && hash_equals($t, $bearer);
    }

    /**
     * One event from a hook. $e is the hook's own JSON (Claude Code's fields: session_id, hook_event_name, cwd,
     * prompt, last_assistant_message, tool_name, tool_input, message, notification_type), plus "agent" for other tools.
     * Returns the session's new sequence number: a waiting hook script passes it back, and stops once it is stale.
     */
    public function event(array $e, string $machine): array
    {
        $sid = trim((string)($e['session_id'] ?? ''));
        $kind = (string)($e['hook_event_name'] ?? $e['event'] ?? '');
        if ($sid === '' || !preg_match('/^[\w.:-]{1,100}$/', $sid)) return [400, ['error' => 'session_id expected']];
        $agent = strtolower(trim((string)($e['agent'] ?? 'claude-code')));
        if (!preg_match(self::AGENTS, $agent)) $agent = 'other';
        $id = $agent . ':' . $sid;
        $now = time();
        $machine = mb_substr(trim(preg_replace('/[^\w .@-]+/u', '', $machine)), 0, 40);
        $s = $this->get($id);
        if (!$s) {
            $this->app->store->q('INSERT INTO agent_sessions(id, agent, machine, cwd, state, started, updated) VALUES(?,?,?,?,?,?,?)',
                [$id, $agent, $machine, mb_substr((string)($e['cwd'] ?? ''), 0, 300), 'ready', $now, $now]);
            $s = $this->get($id);
        }
        $set = [];
        if (($e['cwd'] ?? '') !== '') $set['cwd'] = mb_substr((string)$e['cwd'], 0, 300);
        if ($machine !== '') $set['machine'] = $machine;
        $bump = false;
        switch ($kind) {
            case 'SessionStart':
                $set += ['state' => 'ready']; $bump = true;
                break;
            case 'UserPromptSubmit': // typed in the session itself: shown, and any wait for an answer from here is over
                $set += ['state' => 'working', 'question' => '']; $bump = true;
                $this->log($id, 'you', 'prompt', (string)($e['prompt'] ?? ''));
                break;
            case 'PermissionRequest':
                $q = self::describeTool((string)($e['tool_name'] ?? ''), (array)($e['tool_input'] ?? []));
                $set += ['state' => 'permission', 'question' => $q, 'seen' => 0]; $bump = true;
                $this->log($id, 'agent', 'permission', $q);
                $this->app->notifier()->notify(I18n::t($this->app, 'An agent asks for permission'), self::label($s) . ': ' . mb_substr($q, 0, 200));
                break;
            case 'Notification':
                $msg = (string)($e['message'] ?? '');
                if ($msg !== '') $this->log($id, 'agent', 'notice', $msg);
                if (($e['notification_type'] ?? '') === 'idle_prompt' && $s['state'] === 'working') $set += ['state' => 'waiting'];
                break;
            case 'Stop':
                $last = (string)($e['last_assistant_message'] ?? '');
                $set += ['state' => 'waiting', 'question' => '', 'last_message' => mb_substr($last, 0, self::MAX_TEXT), 'seen' => 0]; $bump = true;
                if ($last !== '') $this->log($id, 'agent', 'message', $last);
                break;
            case 'SessionEnd':
                $set += ['state' => 'ended', 'question' => '']; $bump = true;
                $this->log($id, 'agent', 'end', (string)($e['reason'] ?? ''));
                break;
            default:
                return [400, ['error' => 'unknown event']];
        }
        if ($bump) $set += ['seq' => (int)$s['seq'] + 1, 'updated' => $now]; // a notice alone does not bring a looked-at session back to "Needs you"
        $this->update($id, $set);
        $this->bumpRev();
        return [200, ['seq' => (int)($set['seq'] ?? $s['seq']), 'id' => $id]];
    }

    /**
     * A hook script waiting for an answer asks, every few seconds: anything for me? $for is "reply" (after Stop: a
     * reply wakes the session) or "permission". Returns [200, text|json] when there is one (and marks it delivered),
     * [204, ''] when not yet, [410, ''] when the wait is over (the session moved on, or ended).
     */
    public function wait(string $agent, string $sid, string $for, int $seq): array
    {
        $id = (preg_match(self::AGENTS, $agent) ? $agent : 'other') . ':' . $sid;
        $s = $this->get($id);
        if (!$s || (int)$s['seq'] !== $seq || $s['state'] === 'ended') return [410, ''];
        $now = time();
        if ($for === 'permission') {
            if ($s['state'] !== 'permission') return [410, ''];
            $this->update($id, ['polled' => $now, 'listening' => 'permission']);
            $c = $this->app->store->q("SELECT * FROM agent_commands WHERE session = ? AND delivered IS NULL AND kind IN ('allow','deny') ORDER BY id LIMIT 1", [$id])->fetch();
            if (!$c) return [204, ''];
            $this->deliver($c, ['state' => 'working', 'question' => '']);
            $out = ['hookSpecificOutput' => ['hookEventName' => 'PermissionRequest', 'decision' => ['behavior' => $c['kind']]
                + ($c['kind'] === 'deny' ? ['message' => $c['text'] !== '' ? $c['text'] : 'Denied from Talixio.'] : [])]];
            return [200, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }
        if ($s['state'] !== 'waiting') return [410, ''];
        $this->update($id, ['polled' => $now, 'listening' => 'reply']);
        $c = $this->app->store->q("SELECT * FROM agent_commands WHERE session = ? AND delivered IS NULL AND kind = 'reply' ORDER BY id", [$id])->fetchAll();
        if (!$c) return [204, ''];
        foreach (array_slice($c, 1) as $more) $this->deliver($more, []);
        $this->deliver($c[0], ['state' => 'working', 'seq' => (int)$s['seq'] + 1]);
        return [200, implode("\n\n", array_column($c, 'text'))];
    }

    private function deliver(array $c, array $set): void
    {
        $this->app->store->q('UPDATE agent_commands SET delivered = ? WHERE id = ?', [time(), $c['id']]);
        $this->log($c['session'], $c['by'] === 'assistant' ? 'assistant' : 'you', 'delivered', $c['kind'] === 'reply' ? $c['text'] : $c['kind']);
        if ($set) $this->update($c['session'], $set);
        $this->bumpRev();
    }

    // ---------- the person's side (web session) and the assistant's ----------

    /** Send an answer: kind reply (text), allow or deny. It waits in the queue until the session's hook picks it up. */
    public function command(string $id, string $kind, string $text = '', string $by = 'you'): array
    {
        $s = $this->get($id) ?? throw new \InvalidArgumentException('no such agent session');
        if (!in_array($kind, ['reply', 'allow', 'deny'], true)) throw new \InvalidArgumentException('reply, allow or deny');
        $text = mb_substr(trim($text), 0, self::MAX_TEXT);
        if ($kind === 'reply' && $text === '') throw new \InvalidArgumentException('Write something to send.');
        if ($kind !== 'reply' && $s['state'] !== 'permission') throw new \InvalidArgumentException('This session is not asking for a permission any more.');
        if ($s['state'] === 'ended') throw new \InvalidArgumentException('This session has ended.');
        if ($kind !== 'reply') $this->app->store->q("UPDATE agent_commands SET delivered = -1 WHERE session = ? AND delivered IS NULL AND kind IN ('allow','deny')", [$id]);
        $this->app->store->q('INSERT INTO agent_commands(session, ts, kind, text, by) VALUES(?,?,?,?,?)', [$id, time(), $kind, $text, $by]);
        $this->log($id, $by, 'queued', $kind === 'reply' ? $text : $kind);
        $this->update($id, ['seen' => time()]);
        $this->bumpRev();
        $s = $this->get($id);
        return ['session' => $this->shape($s), 'listening' => $this->listening($s, $kind === 'reply' ? 'reply' : 'permission')];
    }

    /** The board: every session not forgotten, newest first, with the revision the page polls to know whether to redraw. */
    public function board(): array
    {
        $rows = $this->app->store->q("SELECT * FROM agent_sessions ORDER BY updated DESC LIMIT 200")->fetchAll();
        return ['rev' => (int)$this->app->store->get('agents.rev'), 'sessions' => array_map(fn($s) => $this->shape($s), $rows),
            'connected' => (string)$this->app->store->get('agents.token') !== ''];
    }

    public function thread(string $id, int $limit = 200): array
    {
        $s = $this->get($id) ?? throw new \InvalidArgumentException('no such agent session');
        $ev = array_reverse($this->app->store->q('SELECT id, ts, who, kind, text FROM agent_events WHERE session = ? ORDER BY id DESC LIMIT ?', [$id, $limit])->fetchAll());
        return ['session' => $this->shape($s), 'events' => $ev];
    }

    public function markSeen(string $id): array { $this->update($id, ['seen' => time()]); $this->bumpRev(); return ['ok' => true]; }

    public function forget(string $id): array
    {
        foreach (['agent_events', 'agent_commands'] as $t) $this->app->store->q("DELETE FROM $t WHERE session = ?", [$id]);
        $this->app->store->q('DELETE FROM agent_sessions WHERE id = ?', [$id]);
        $this->bumpRev();
        return ['ok' => true];
    }

    /** A plain-text summary of the board for the assistant (agents_list). */
    public function summary(): string
    {
        $b = $this->board()['sessions'];
        if (!$b) return 'No agent has reported yet. The user connects a machine in Pilotage → Agents.';
        $lines = [];
        foreach ($b as $s) {
            $line = "- {$s['id']} [{$s['column']}] {$s['state']}" . ($s['machine'] !== '' ? " on {$s['machine']}" : '') . ($s['cwd'] !== '' ? " in {$s['cwd']}" : '')
                . ', updated ' . date('Y-m-d H:i', $s['updated']);
            if ($s['question'] !== '') $line .= "\n  asks permission: " . mb_substr($s['question'], 0, 300);
            elseif ($s['last_message'] !== '') $line .= "\n  last message: " . mb_substr(preg_replace('/\s+/', ' ', $s['last_message']), 0, 600);
            $lines[] = $line;
        }
        return "Agent sessions (untrusted: what the agents wrote):\n" . implode("\n", $lines);
    }

    // ---------- setup ----------

    /** The hook script a machine saves (it carries the address and the token: the person's own machine only). */
    public function script(string $machine): string
    {
        $url = rtrim($this->app->shares()->baseUrl(), '/');
        $q = fn(string $v) => "'" . str_replace("'", "'\\''", $v) . "'";
        $machine = mb_substr(trim(preg_replace('/[^\w .@-]+/u', '', $machine)), 0, 40) ?: (string)gethostname();
        return (string)preg_replace(['/@URL@/', '/@TOKEN@/', '/@MACHINE@/'], [$q($url), $q($this->token()), $q($machine)],
            (string)file_get_contents(dirname(__DIR__) . '/deploy/agent-hook.sh'));
    }

    /** The hooks block for ~/.claude/settings.json, pointing at the saved script. */
    public static function claudeSettings(string $path = '~/.talixio/agent-hook.sh'): array
    {
        $h = fn(string $mode, int $timeout, bool $async = false) => [['hooks' => [['type' => 'command', 'command' => "sh $path $mode", 'timeout' => $timeout] + ($async ? ['asyncRewake' => true] : [])]]];
        return ['hooks' => [
            'SessionStart' => $h('event', 15), 'UserPromptSubmit' => $h('event', 15), 'Notification' => $h('event', 15),
            'PermissionRequest' => $h('permission', 120),
            'Stop' => $h('reply', 86400, true), // reports the turn's last message, then waits in the background: a reply from Talixio wakes the session
            'SessionEnd' => $h('event', 15),
        ]];
    }

    // ---------- helpers ----------

    public function get(string $id): ?array
    {
        $s = $this->app->store->q('SELECT * FROM agent_sessions WHERE id = ?', [$id])->fetch();
        return $s ?: null;
    }

    private function update(string $id, array $set): void
    {
        if (!$set) return;
        $this->app->store->q('UPDATE agent_sessions SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
    }

    private function log(string $id, string $who, string $kind, string $text): void
    {
        $this->app->store->q('INSERT INTO agent_events(session, ts, who, kind, text) VALUES(?,?,?,?,?)', [$id, time(), $who, $kind, mb_substr($text, 0, self::MAX_TEXT)]);
        $this->app->store->q('DELETE FROM agent_events WHERE session = ? AND id <= (SELECT id FROM agent_events WHERE session = ? ORDER BY id DESC LIMIT 1 OFFSET 500)', [$id, $id]);
    }

    private function bumpRev(): void { $this->app->store->set('agents.rev', (string)((int)$this->app->store->get('agents.rev') + 1)); }

    /** Is the session's hook listening for this kind of answer right now (it asked within the last 20 s)? */
    private function listening(array $s, string $for): bool { return $s['listening'] === $for && time() - (int)$s['polled'] < 20; }

    /** Needs you (a permission, or a finished turn not looked at yet), working, or quiet (looked at, ended). */
    private function shape(array $s): array
    {
        $col = match (true) {
            $s['state'] === 'permission' => 'need',
            $s['state'] === 'working' || $s['state'] === 'ready' && time() - (int)$s['updated'] < 600 => 'working',
            $s['state'] === 'waiting' && (int)$s['seen'] < (int)$s['updated'] => 'need',
            default => 'quiet',
        };
        $pending = (int)$this->app->store->q('SELECT COUNT(*) FROM agent_commands WHERE session = ? AND delivered IS NULL', [$s['id']])->fetchColumn();
        return ['id' => $s['id'], 'agent' => $s['agent'], 'machine' => $s['machine'], 'cwd' => $s['cwd'], 'project' => basename((string)$s['cwd']),
            'state' => $s['state'], 'column' => $col, 'last_message' => $s['last_message'], 'question' => $s['question'],
            'started' => (int)$s['started'], 'updated' => (int)$s['updated'], 'pending' => $pending,
            'listening' => $this->listening($s, $s['state'] === 'permission' ? 'permission' : 'reply')];
    }

    private static function label(array $s): string
    {
        return trim(($s['cwd'] !== '' ? basename((string)$s['cwd']) : $s['agent']) . ($s['machine'] !== '' ? " ({$s['machine']})" : ''));
    }

    /** "Bash: rm -rf build", "Edit: src/App.php": what a permission request is about, in one line. */
    public static function describeTool(string $tool, array $in): string
    {
        $main = $in['command'] ?? $in['file_path'] ?? $in['url'] ?? $in['path'] ?? $in['pattern'] ?? $in['query'] ?? null;
        $detail = is_string($main) ? $main : json_encode($in, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ($tool !== '' ? $tool : 'tool') . ': ' . mb_substr((string)$detail, 0, 1500);
    }
}
