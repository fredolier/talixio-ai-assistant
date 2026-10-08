<?php
declare(strict_types=1);
namespace Talixio;

/** SQLite storage shared by the worker, job processes and the web app (WAL mode). */
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
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=15000;');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY, role TEXT NOT NULL, channel TEXT NOT NULL, content TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS tasks(id INTEGER PRIMARY KEY, title TEXT NOT NULL, prompt TEXT NOT NULL, cron TEXT, next_run INTEGER, enabled INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, last_run INTEGER);
            CREATE TABLE IF NOT EXISTS tool_log(id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, tool TEXT NOT NULL, args TEXT NOT NULL, result TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS usage(id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, model TEXT NOT NULL, input_tokens INTEGER NOT NULL, output_tokens INTEGER NOT NULL, cost_usd REAL, source TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS kv(k TEXT PRIMARY KEY, v TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY, title TEXT NOT NULL, instructions TEXT NOT NULL, tier TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'queued', result TEXT, cost_usd REAL NOT NULL DEFAULT 0, max_cost_usd REAL NOT NULL,
                created INTEGER NOT NULL, started INTEGER, finished INTEGER);
            CREATE TABLE IF NOT EXISTS summaries(id INTEGER PRIMARY KEY, from_id INTEGER NOT NULL, to_id INTEGER NOT NULL, ts_from INTEGER NOT NULL, ts_to INTEGER NOT NULL, text TEXT NOT NULL);
            -- RAG: every searchable item is a doc; FTS5 for keywords, embeddings for meaning
            CREATE TABLE IF NOT EXISTS docs(id INTEGER PRIMARY KEY, kind TEXT NOT NULL, ref INTEGER NOT NULL, ts INTEGER NOT NULL, label TEXT NOT NULL, text TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS docs_kind_ref ON docs(kind, ref);
            CREATE VIRTUAL TABLE IF NOT EXISTS docs_fts USING fts5(text, content='docs', content_rowid='id', tokenize='unicode61 remove_diacritics 2');
            CREATE TRIGGER IF NOT EXISTS docs_ai AFTER INSERT ON docs BEGIN INSERT INTO docs_fts(rowid, text) VALUES (new.id, new.text); END;
            CREATE TRIGGER IF NOT EXISTS docs_ad AFTER DELETE ON docs BEGIN INSERT INTO docs_fts(docs_fts, rowid, text) VALUES('delete', old.id, old.text); END;
            CREATE TABLE IF NOT EXISTS embeddings(doc_id INTEGER PRIMARY KEY, model TEXT NOT NULL, vec BLOB NOT NULL);
            SQL);
        $this->addColumn('messages', 'meta', 'TEXT');
        $this->addColumn('usage', 'tier', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('usage', 'job_id', 'INTEGER');
        $this->addColumn('usage', 'cache_read', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('usage', 'cache_write', 'INTEGER NOT NULL DEFAULT 0');
        if ($this->get('docs_backfilled') === '') { // index history written before RAG existed
            $this->db->exec("INSERT INTO docs(kind, ref, ts, label, text)
                SELECT 'message', id, ts, role || ' via ' || channel, content FROM messages
                WHERE role IN ('user','assistant') AND id NOT IN (SELECT ref FROM docs WHERE kind='message')");
            $this->set('docs_backfilled', '1');
        }
    }

    private function addColumn(string $table, string $col, string $def): void
    {
        $cols = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!in_array($col, $cols, true)) $this->db->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }

    public function q(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st;
    }

    // --- messages (one conversation thread shared by every channel) ---
    public function addMessage(string $role, string $channel, string $content, ?array $meta = null): int
    {
        $this->q('INSERT INTO messages(role,channel,content,ts,meta) VALUES(?,?,?,?,?)',
            [$role, $channel, $content, time(), $meta ? json_encode($meta) : null]);
        $id = (int)$this->db->lastInsertId();
        [$label, $text] = match (true) { // index what was said, without our internal instructions
            $channel === 'job' && $role === 'user' => ['job report', preg_replace('/^\[[^\]]*\]\s*/', '', $content)],
            $channel === 'schedule' && $role === 'user' => ['scheduled task', preg_replace('/^\[[^\]]*\]\s*/', '', $content)],
            default => ["$role via $channel", $content],
        };
        $this->addDoc('message', $id, $label, (string)$text);
        return $id;
    }

    public function maxMessageId(): int { return (int)$this->db->query('SELECT COALESCE(MAX(id),0) FROM messages')->fetchColumn(); }

    public function messagesAfter(int $after, int $limit = 200): array
    {
        $rows = $after <= 0
            ? array_reverse($this->q('SELECT * FROM messages ORDER BY id DESC LIMIT ?', [$limit])->fetchAll())
            : $this->q('SELECT * FROM messages WHERE id > ? ORDER BY id LIMIT ?', [$after, $limit])->fetchAll();
        return array_map(fn($r) => ['meta' => $r['meta'] ? json_decode($r['meta'], true) : null] + $r, $rows);
    }

    /** Recent history shaped for LLM APIs: alternating roles, starting with the user. Also returns the oldest id included. */
    public function history(int $limit, ?int &$oldestId = null): array
    {
        $rows = array_reverse($this->q('SELECT id, role, content FROM messages ORDER BY id DESC LIMIT ?', [$limit])->fetchAll());
        $oldestId = $rows ? (int)$rows[0]['id'] : PHP_INT_MAX;
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

    public function updateTask(int $id, array $fields): void { $this->update('tasks', $id, $fields); }

    private function update(string $table, int $id, array $fields): void
    {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        $this->q("UPDATE $table SET $sets WHERE id=?", [...array_values($fields), $id]);
    }

    // --- background jobs (sub-agents) ---
    public function addJob(string $title, string $instructions, string $tier, float $maxCost): int
    {
        $this->q('INSERT INTO jobs(title,instructions,tier,max_cost_usd,created) VALUES(?,?,?,?,?)', [$title, $instructions, $tier, $maxCost, time()]);
        return (int)$this->db->lastInsertId();
    }

    public function job(int $id): ?array { return $this->q('SELECT * FROM jobs WHERE id=?', [$id])->fetch() ?: null; }
    public function jobs(int $limit = 50): array { return $this->q('SELECT * FROM jobs ORDER BY id DESC LIMIT ?', [$limit])->fetchAll(); }
    public function jobsWithStatus(string $status): array { return $this->q('SELECT * FROM jobs WHERE status=? ORDER BY id', [$status])->fetchAll(); }
    public function updateJob(int $id, array $fields): void { $this->update('jobs', $id, $fields); }

    /** Atomically move a queued job to running. */
    public function claimJob(int $id): bool
    {
        return $this->q("UPDATE jobs SET status='running', started=? WHERE id=? AND status='queued'", [time(), $id])->rowCount() === 1;
    }

    // --- audit and usage ---
    public function logTool(string $tool, array $args, string $result): void
    {
        $this->q('INSERT INTO tool_log(ts,tool,args,result) VALUES(?,?,?,?)',
            [time(), $tool, json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($result, 0, 2000)]);
    }

    public function toolLog(int $limit = 100): array { return $this->q('SELECT * FROM tool_log ORDER BY id DESC LIMIT ?', [$limit])->fetchAll(); }

    public function addUsage(array $u, ?float $cost, string $source, string $tier, ?int $jobId): void
    {
        $this->q('INSERT INTO usage(ts,model,input_tokens,output_tokens,cache_read,cache_write,cost_usd,source,tier,job_id) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [time(), $u['model'], $u['in'], $u['out'], $u['cache_read'] ?? 0, $u['cache_write'] ?? 0, $cost, $source, $tier, $jobId]);
    }

    public function spentSince(int $ts): float
    {
        return (float)$this->q('SELECT COALESCE(SUM(cost_usd),0) FROM usage WHERE ts >= ?', [$ts])->fetchColumn();
    }

    public function usageByDay(int $days = 30): array
    {
        return $this->q("SELECT date(ts,'unixepoch','localtime') AS day, SUM(input_tokens+cache_read+cache_write) AS input, SUM(output_tokens) AS output,
                         SUM(cost_usd) AS cost, COUNT(*) AS calls FROM usage WHERE ts > ? GROUP BY day ORDER BY day", [time() - $days * 86400])->fetchAll();
    }

    public function usageBy(string $col, int $since): array
    {
        $col = in_array($col, ['tier', 'source', 'model'], true) ? $col : 'tier';
        return $this->q("SELECT $col AS name, COUNT(*) AS calls, SUM(input_tokens+cache_read+cache_write) AS input, SUM(output_tokens) AS output, SUM(cost_usd) AS cost
                         FROM usage WHERE ts >= ? GROUP BY $col ORDER BY cost DESC", [$since])->fetchAll();
    }

    // --- searchable documents ---
    public function addDoc(string $kind, int $ref, string $label, string $text): void
    {
        if (mb_strlen(trim($text)) < 3) return;
        $this->q('INSERT INTO docs(kind,ref,ts,label,text) VALUES(?,?,?,?,?)', [$kind, $ref, time(), $label, $text]);
    }

    public function addSummary(int $fromId, int $toId, int $tsFrom, int $tsTo, string $text): int
    {
        $this->q('INSERT INTO summaries(from_id,to_id,ts_from,ts_to,text) VALUES(?,?,?,?,?)', [$fromId, $toId, $tsFrom, $tsTo, $text]);
        $id = (int)$this->db->lastInsertId();
        $this->q('INSERT INTO docs(kind,ref,ts,label,text) VALUES(?,?,?,?,?)',
            ['summary', $id, $tsTo, 'summary of ' . date('Y-m-d', $tsFrom) . ($tsTo - $tsFrom > 86400 ? ' to ' . date('Y-m-d', $tsTo) : ''), $text]);
        return $id;
    }

    // --- small key/value state ---
    public function get(string $k, string $default = ''): string
    {
        $v = $this->q('SELECT v FROM kv WHERE k=?', [$k])->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public function set(string $k, string $v): void { $this->q('INSERT OR REPLACE INTO kv(k,v) VALUES(?,?)', [$k, $v]); }
}
