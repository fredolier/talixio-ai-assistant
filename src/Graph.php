<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The library's knowledge graph (TALIXIO_GRAPH, off by default): the people, companies, projects and places each
 * document mentions, extracted in the background by the fast model, so kb_related can answer "everything about X"
 * and "what is linked to this file". A file is read again only when its text changed; at most TALIXIO_GRAPH_DAILY
 * files a day. A file whose folder the model may not read (Secret without a local model...) is skipped.
 */
final class Graph
{
    public const KINDS = ['person', 'company', 'project', 'place', 'topic'];
    public const PER_TICK = 10;

    /** Tests: fn(string $prompt): string, instead of the model. */
    public static $fake = null;

    public function __construct(private App $app) {}

    public function enabled(): bool { return $this->app->cfg->graph; }

    private function schema(): void
    {
        $this->app->store->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS graph_entities(id INTEGER PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL, norm TEXT NOT NULL, UNIQUE(kind, norm));
            CREATE TABLE IF NOT EXISTS graph_links(entity INTEGER NOT NULL, file INTEGER NOT NULL, PRIMARY KEY(entity, file));
            CREATE INDEX IF NOT EXISTS graph_links_file ON graph_links(file);
            CREATE TABLE IF NOT EXISTS graph_files(file INTEGER PRIMARY KEY, hash TEXT NOT NULL, ts INTEGER NOT NULL, n INTEGER NOT NULL);
            SQL);
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        $t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
        return preg_replace('/\p{Mn}+/u', '', (string)$t);
    }

    /** Called by the worker: reads a few new or changed files. Returns how many were read. */
    public function tick(int $now): int
    {
        if (!$this->enabled()) return 0;
        $this->schema();
        $s = $this->app->store;
        $day = date('Y-m-d', $now);
        $used = $s->get('graph_day', '') === $day ? (int)$s->get('graph_used', '0') : 0;
        $left = min(self::PER_TICK, max(0, $this->app->cfg->graphDaily - $used));
        $s->q('DELETE FROM graph_links WHERE file NOT IN (SELECT id FROM files WHERE deleted_at IS NULL)');
        $s->q('DELETE FROM graph_files WHERE file NOT IN (SELECT id FROM files WHERE deleted_at IS NULL)');
        if ($left === 0) return 0;
        $rows = $s->q("SELECT f.id, f.name, f.folder, f.text, g.hash FROM files f LEFT JOIN graph_files g ON g.file = f.id
            WHERE f.status = 'ready' AND f.deleted_at IS NULL AND f.text IS NOT NULL AND f.text != '' ORDER BY f.id DESC")->fetchAll();
        $done = 0;
        foreach ($rows as $f) {
            if ($done >= $left) break;
            $hash = md5((string)$f['text']);
            if ($f['hash'] === $hash) continue;
            $level = $this->app->privacy()->folderLevel((string)$f['folder']);
            if (Library::readonly((string)$f['folder']) || $this->app->privacy()->modelBlocked($level) !== null || str_starts_with((string)$f['text'], '(')) {
                $this->save((int)$f['id'], $hash, $now, []); // not read (FAQ, privacy, no text): kept out until it changes
                continue;
            }
            $this->save((int)$f['id'], $hash, $now, $this->extract((string)$f['name'], (string)$f['text']));
            $done++;
        }
        $s->set('graph_day', $day);
        $s->set('graph_used', (string)($used + $done));
        return $done;
    }

    /** The entities a document mentions: [['kind' => ..., 'name' => ...]]. */
    public function extract(string $name, string $text): array
    {
        $prompt = "List the named entities this document is about: people, companies or organisations, projects, places, and at most 3 main topics. "
            . "Use each one's full usual name. Reply with JSON only: [{\"kind\": \"person|company|project|place|topic\", \"name\": \"...\"}], at most 20, [] if none.\n\n"
            . "Document: $name\n\n" . mb_substr($text, 0, 6000);
        if (self::$fake) $reply = (self::$fake)($prompt);
        else {
            try { [$reply] = $this->app->agent()->run('graph', 'fast', 'none', [['role' => 'user', 'content' => $prompt]], '', 'summary'); }
            catch (Abort) { return []; }
        }
        if (!preg_match('/\[.*\]/s', (string)$reply, $m)) return [];
        $out = [];
        foreach (json_decode($m[0], true) ?: [] as $e) {
            $kind = strtolower((string)($e['kind'] ?? ''));
            $n = trim((string)($e['name'] ?? ''));
            if (!in_array($kind, self::KINDS, true) || $n === '' || mb_strlen($n) > 80) continue;
            $out[$kind . ':' . self::norm($n)] = ['kind' => $kind, 'name' => $n];
        }
        return array_values(array_slice($out, 0, 20));
    }

    private function save(int $file, string $hash, int $now, array $entities): void
    {
        $s = $this->app->store;
        $s->q('DELETE FROM graph_links WHERE file = ?', [$file]);
        foreach ($entities as $e) {
            $s->q('INSERT OR IGNORE INTO graph_entities(kind, name, norm) VALUES(?, ?, ?)', [$e['kind'], $e['name'], self::norm($e['name'])]);
            $id = (int)$s->q('SELECT id FROM graph_entities WHERE kind = ? AND norm = ?', [$e['kind'], self::norm($e['name'])])->fetchColumn();
            $s->q('INSERT OR IGNORE INTO graph_links(entity, file) VALUES(?, ?)', [$id, $file]);
        }
        $s->q('INSERT OR REPLACE INTO graph_files(file, hash, ts, n) VALUES(?, ?, ?, ?)', [$file, $hash, $now, count($entities)]);
    }

    /** Files the current work may read, among these ids, with their folder and name. */
    private function readable(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->app->store->q("SELECT id, name, folder FROM files WHERE id IN ($in) AND deleted_at IS NULL")->fetchAll() as $f) {
            $level = $this->app->privacy()->folderLevel((string)$f['folder']);
            if ($this->app->privacy()->mayRead($level, null, (string)$f['folder'])) $out[(int)$f['id']] = $f;
        }
        return $out;
    }

    /**
     * What is linked: for a file id, its entities and the files sharing the most of them; for a name, the matching
     * entities and their files.
     */
    public function related(string $query, int $limit = 15): array
    {
        $this->schema();
        $s = $this->app->store;
        if (preg_match('/^#?(\d+)$/', trim($query), $m)) {
            $file = (int)$m[1];
            if (!$this->readable([$file])) return ['entities' => [], 'files' => []];
            $ents = $s->q('SELECT e.id, e.kind, e.name FROM graph_links l JOIN graph_entities e ON e.id = l.entity WHERE l.file = ? ORDER BY e.kind, e.name', [$file])->fetchAll();
            $shared = $s->q('SELECT l2.file, COUNT(*) AS n, GROUP_CONCAT(e.name, \', \') AS via FROM graph_links l1 JOIN graph_links l2 ON l2.entity = l1.entity AND l2.file != l1.file
                JOIN graph_entities e ON e.id = l1.entity WHERE l1.file = ? GROUP BY l2.file ORDER BY n DESC LIMIT 200', [$file])->fetchAll();
        } else {
            $q = self::norm($query);
            if ($q === '') return ['entities' => [], 'files' => []];
            $ents = $s->q("SELECT id, kind, name FROM graph_entities WHERE norm = ? OR norm LIKE ? ESCAPE '\\' ORDER BY (norm = ?) DESC, length(norm) LIMIT 10",
                [$q, '%' . addcslashes($q, '%_\\') . '%', $q])->fetchAll();
            if (!$ents) return ['entities' => [], 'files' => []];
            $in = implode(',', array_map(fn($e) => (int)$e['id'], $ents));
            $shared = $s->q("SELECT l.file, COUNT(*) AS n, GROUP_CONCAT(e.name, ', ') AS via FROM graph_links l JOIN graph_entities e ON e.id = l.entity
                WHERE l.entity IN ($in) GROUP BY l.file ORDER BY n DESC, l.file DESC LIMIT 200")->fetchAll();
        }
        $ok = $this->readable(array_column($shared, 'file'));
        $files = [];
        foreach ($shared as $r) {
            if (!isset($ok[(int)$r['file']]) || count($files) >= $limit) continue;
            $f = $ok[(int)$r['file']];
            $files[] = ['id' => (int)$f['id'], 'label' => Library::label($f), 'shared' => (int)$r['n'], 'via' => (string)$r['via']];
        }
        // an entity is shown only when a readable file mentions it (no names from hidden documents)
        if (!preg_match('/^#?\d+$/', trim($query))) {
            $seen = [];
            foreach ($files as $f) foreach (explode(', ', $f['via']) as $v) $seen[self::norm($v)] = true;
            $ents = array_values(array_filter($ents, fn($e) => isset($seen[self::norm($e['name'])])));
        }
        return ['entities' => array_map(fn($e) => ['kind' => $e['kind'], 'name' => $e['name']], $ents), 'files' => $files];
    }

    public function stats(): array
    {
        $this->schema();
        $s = $this->app->store;
        return ['files' => (int)$s->q('SELECT COUNT(*) FROM graph_files WHERE n > 0')->fetchColumn(), 'entities' => (int)$s->q('SELECT COUNT(*) FROM graph_entities')->fetchColumn()];
    }
}
