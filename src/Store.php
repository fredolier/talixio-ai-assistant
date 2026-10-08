<?php
declare(strict_types=1);
namespace Talixio;

/** SQLite storage shared by the worker and the web app (WAL mode allows both). */
final class Store
{
    public \PDO $db;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
        $this->db = new \PDO("sqlite:$path", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000;');
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY, role TEXT NOT NULL, channel TEXT NOT NULL, content TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS tasks(id INTEGER PRIMARY KEY, title TEXT NOT NULL, prompt TEXT NOT NULL, cron TEXT, next_run INTEGER, enabled INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, last_run INTEGER);
            CREATE TABLE IF NOT EXISTS tool_log(id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, tool TEXT NOT NULL, args TEXT NOT NULL, result TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS usage(id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, model TEXT NOT NULL, input_tokens INTEGER NOT NULL, output_tokens INTEGER NOT NULL, cost_usd REAL, source TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS kv(k TEXT PRIMARY KEY, v TEXT NOT NULL);
            SQL);
    }

    private function q(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st;
    }

    // --- messages (one conversation thread shared by every channel) ---
    public function addMessage(string $role, string $channel, string $content): int
    {
        $this->q('INSERT INTO messages(role,channel,content,ts) VALUES(?,?,?,?)', [$role, $channel, $content, time()]);
        return (int)$this->db->lastInsertId();
    }

    public function messagesAfter(int $after, int $limit = 200): array
    {
        if ($after <= 0) { // initial load: latest page
            return array_reverse($this->q('SELECT * FROM messages ORDER BY id DESC LIMIT ?', [$limit])->fetchAll());
        }
        return $this->q('SELECT * FROM messages WHERE id > ? ORDER BY id LIMIT ?', [$after, $limit])->fetchAll();
    }

    /** Recent history shaped for LLM APIs: alternating roles, starting with the user. */
    public function history(int $limit): array
    {
        $rows = array_reverse($this->q('SELECT role, content FROM messages ORDER BY id DESC LIMIT ?', [$limit])->fetchAll());
        $out = [];
        foreach ($rows as $r) {
            if ($out && end($out)['role'] === $r['role']) {
                $out[count($out) - 1]['content'] .= "\n\n" . $r['content'];
            } else {
                $out[] = ['role' => $r['role'], 'content' => $r['content']];
            }
        }
        while ($out && $out[0]['role'] !== 'user') array_shift($out);
        return $out;
    }

    // --- tasks ---
    public function addTask(string $title, string $prompt, ?string $cron, int $nextRun): int
    {
        $this->q('INSERT INTO tasks(title,prompt,cron,next_run,enabled,created) VALUES(?,?,?,?,1,?)', [$title, $prompt, $cron, $nextRun, time()]);
        return (int)$this->db->lastInsertId();
    }

    public function tasks(): array { return $this->q('SELECT * FROM tasks ORDER BY enabled DESC, next_run')->fetchAll(); }
    public function task(int $id): ?array { return $this->q('SELECT * FROM tasks WHERE id=?', [$id])->fetch() ?: null; }
    public function dueTasks(int $now): array { return $this->q('SELECT * FROM tasks WHERE enabled=1 AND next_run <= ? ORDER BY next_run', [$now])->fetchAll(); }
    public function deleteTask(int $id): bool { return $this->q('DELETE FROM tasks WHERE id=?', [$id])->rowCount() > 0; }

    public function updateTask(int $id, array $fields): void
    {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        $this->q("UPDATE tasks SET $sets WHERE id=?", [...array_values($fields), $id]);
    }

    // --- audit and usage ---
    public function logTool(string $tool, array $args, string $result): void
    {
        $this->q('INSERT INTO tool_log(ts,tool,args,result) VALUES(?,?,?,?)',
            [time(), $tool, json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($result, 0, 2000)]);
    }

    public function toolLog(int $limit = 100): array { return $this->q('SELECT * FROM tool_log ORDER BY id DESC LIMIT ?', [$limit])->fetchAll(); }

    public function addUsage(string $model, int $in, int $out, ?float $cost, string $source): void
    {
        $this->q('INSERT INTO usage(ts,model,input_tokens,output_tokens,cost_usd,source) VALUES(?,?,?,?,?,?)', [time(), $model, $in, $out, $cost, $source]);
    }

    public function usageByDay(int $days = 30): array
    {
        return $this->q("SELECT date(ts,'unixepoch','localtime') AS day, SUM(input_tokens) AS input, SUM(output_tokens) AS output, SUM(cost_usd) AS cost, COUNT(*) AS calls
                         FROM usage WHERE ts > ? GROUP BY day ORDER BY day", [time() - $days * 86400])->fetchAll();
    }

    // --- small key/value state ---
    public function get(string $k, string $default = ''): string
    {
        $v = $this->q('SELECT v FROM kv WHERE k=?', [$k])->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public function set(string $k, string $v): void { $this->q('INSERT OR REPLACE INTO kv(k,v) VALUES(?,?)', [$k, $v]); }
}
