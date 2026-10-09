<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Tasks (a Trello-like board per space): cards with a code (MAI-12), a Markdown description, a status
 * column, a priority P1 (most urgent) to P5, start and due dates, an estimated workload and the time spent
 * (for planning and a future Gantt chart), tags, and dependencies (possibly across spaces).
 * Each space manages its own cards; General can see and arbitrate them all (global order).
 * Deleted cards go to a trash for 30 days; every change is logged and can be undone.
 * Not to be confused with routines (scheduled prompts, the `tasks` table).
 */
final class Board
{
    public const STATUSES = ['idea' => 'Ideas', 'todo' => 'To do', 'doing' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'];
    public const HOURS_PER_DAY = 7;
    private const FIELDS = ['title', 'body', 'status', 'priority', 'start_date', 'due_date', 'estimate_hours', 'tags', 'in_calendar', 'space', 'links'];

    public function __construct(private App $app)
    {
        $db = $app->store->db;
        $db->exec("CREATE TABLE IF NOT EXISTS cards(id INTEGER PRIMARY KEY, space INTEGER NOT NULL DEFAULT 0, code TEXT NOT NULL UNIQUE, title TEXT NOT NULL,
                body TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'todo', priority INTEGER NOT NULL DEFAULT 3, rank REAL NOT NULL DEFAULT 0, global_rank REAL,
                start_date TEXT, due_date TEXT, estimate_hours REAL, tags TEXT NOT NULL DEFAULT '', links TEXT NOT NULL DEFAULT '[]', in_calendar INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, updated INTEGER NOT NULL, done_at INTEGER, deleted_at INTEGER, created_by TEXT NOT NULL DEFAULT 'you');
            CREATE INDEX IF NOT EXISTS cards_space ON cards(space, status, rank);
            CREATE TABLE IF NOT EXISTS card_deps(card INTEGER NOT NULL, depends_on INTEGER NOT NULL, PRIMARY KEY(card, depends_on));
            CREATE INDEX IF NOT EXISTS card_deps_rev ON card_deps(depends_on);
            CREATE TABLE IF NOT EXISTS card_log(id INTEGER PRIMARY KEY, card INTEGER NOT NULL, ts INTEGER NOT NULL, who TEXT NOT NULL, action TEXT NOT NULL,
                what TEXT NOT NULL, before TEXT, undone INTEGER NOT NULL DEFAULT 0);
            CREATE INDEX IF NOT EXISTS card_log_card ON card_log(card, id);
            CREATE TABLE IF NOT EXISTS card_time(id INTEGER PRIMARY KEY, card INTEGER NOT NULL, day TEXT NOT NULL, hours REAL NOT NULL, note TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS card_time_card ON card_time(card);");
    }

    // ---------- reading ----------

    public function get(int $id): ?array
    {
        $c = $this->app->store->q('SELECT * FROM cards WHERE id = ?', [$id])->fetch();
        return $c ? $this->shape($c) : null;
    }

    /** "MAI-12", "mai-12", "#7" or "7". */
    public function find(string $ref): ?array
    {
        $ref = trim($ref);
        if (preg_match('/^#?(\d+)$/', $ref, $m)) return $this->get((int)$m[1]);
        $id = $this->app->store->q('SELECT id FROM cards WHERE code = ? COLLATE NOCASE', [$ref])->fetchColumn();
        return $id ? $this->get((int)$id) : null;
    }

    public function need(string|int $ref): array
    {
        $c = is_int($ref) ? $this->get($ref) : $this->find($ref);
        return $c ?? throw new \InvalidArgumentException("no task $ref");
    }

    private function shape(array $c): array
    {
        foreach (['id', 'space', 'priority', 'in_calendar', 'version', 'created', 'updated'] as $k) $c[$k] = (int)$c[$k];
        foreach (['rank', 'global_rank', 'estimate_hours'] as $k) $c[$k] = $c[$k] === null ? null : (float)$c[$k];
        foreach (['done_at', 'deleted_at'] as $k) $c[$k] = $c[$k] === null ? null : (int)$c[$k];
        $c['tags'] = Store::tagList($c['tags']);
        $c['links'] = json_decode($c['links'], true) ?: [];
        $c['spent_hours'] = (float)$this->app->store->q('SELECT COALESCE(SUM(hours),0) FROM card_time WHERE card = ?', [$c['id']])->fetchColumn();
        preg_match_all('/^\s*[-*+]\s+\[([ xX])\]/m', $c['body'], $m);
        $c['checklist'] = ['done' => count(array_filter($m[1], fn($x) => $x !== ' ')), 'total' => count($m[1])];
        $c['depends_on'] = array_map('intval', $this->app->store->q('SELECT depends_on FROM card_deps WHERE card = ?', [$c['id']])->fetchAll(\PDO::FETCH_COLUMN));
        $c['blocks'] = array_map('intval', $this->app->store->q('SELECT card FROM card_deps WHERE depends_on = ?', [$c['id']])->fetchAll(\PDO::FETCH_COLUMN));
        return $c;
    }

    /**
     * Cards, by column order. $f: space (int, or null for all), status, tag, q (search), done_days (show done cards
     * finished in the last N days; default 14), trash (only deleted ones).
     */
    public function list(array $f = []): array
    {
        $where = []; $args = [];
        if (!empty($f['trash'])) $where[] = 'deleted_at IS NOT NULL'; else $where[] = 'deleted_at IS NULL';
        if (array_key_exists('space', $f) && $f['space'] !== null && $f['space'] !== '') { $where[] = 'space = ?'; $args[] = (int)$f['space']; }
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) { $where[] = 'status = ?'; $args[] = $f['status']; }
        if (!empty($f['open'])) $where[] = "status != 'done'";
        if (!empty($f['tag'])) { $where[] = "tags LIKE ? ESCAPE '\\'"; $args[] = '%,' . addcslashes(mb_strtolower((string)$f['tag']), '%_\\') . ',%'; }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string)$f['q'], '%_\\') . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\' OR code LIKE ? ESCAPE '\\')"; array_push($args, $like, $like, $like);
        }
        if (empty($f['trash']) && empty($f['status'])) { $where[] = "(status != 'done' OR done_at > ?)"; $args[] = time() - 86400 * (int)($f['done_days'] ?? 14); }
        $order = !empty($f['global']) ? 'global_rank IS NULL, global_rank, priority, due_date IS NULL, due_date, rank' : (!empty($f['trash']) ? 'deleted_at DESC' : 'rank, id');
        $rows = $this->app->store->q('SELECT * FROM cards WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 1000", $args)->fetchAll();
        return array_map(fn($c) => $this->shape($c), $rows);
    }

    /** Short view of a card from another space (isolation: title, status, dates only). */
    public function brief(array $c): array
    {
        return array_intersect_key($c, array_flip(['id', 'code', 'title', 'status', 'priority', 'space', 'start_date', 'due_date', 'deleted_at']));
    }

    public function tags(?int $space = null): array
    {
        $count = [];
        $rows = $this->app->store->q('SELECT tags FROM cards WHERE deleted_at IS NULL' . ($space !== null ? ' AND space = ?' : ''), $space !== null ? [$space] : [])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $t) foreach (Store::tagList($t) as $x) $count[$x] = ($count[$x] ?? 0) + 1;
        arsort($count);
        return $count;
    }

    // ---------- codes ----------

    /** The space's code prefix (3 letters from its name unless set): GEN, MAI... */
    public function prefix(int $space): string
    {
        $sp = $this->app->spaces()->get($space) ?? $this->app->spaces()->general();
        if (!empty($sp['code'])) return $sp['code'];
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $sp['name']) ?: $sp['name']));
        $p = substr(($letters ?: 'TSK') . 'XXX', 0, 3);
        if ($space !== 0) { // keep it, and avoid two spaces with the same prefix
            $taken = $this->app->store->q("SELECT code FROM spaces WHERE id != ? AND code != ''", [$space])->fetchAll(\PDO::FETCH_COLUMN);
            for ($i = 2; in_array($p, $taken, true) || $p === 'GEN'; $i++) $p = substr($p, 0, 2) . $i;
            $this->app->store->q('UPDATE spaces SET code = ? WHERE id = ?', [$p, $space]);
        }
        return $p;
    }

    public static function cleanPrefix(string $p): string
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p));
        if (!preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $p)) throw new \InvalidArgumentException('A task code is 2 to 6 letters or digits, starting with a letter (e.g. MAI).');
        return $p;
    }

    private function nextCode(int $space): string
    {
        $p = $this->prefix($space); $st = $this->app->store;
        $n = (int)$st->get("card_seq_$p", '0');
        do { $n++; $code = "$p-$n"; } while ($st->q('SELECT 1 FROM cards WHERE code = ?', [$code])->fetchColumn());
        $st->set("card_seq_$p", (string)$n);
        return $code;
    }

    // ---------- values ----------

    /** "2h", "1.5", "90m", "2d"/"2j" (days of 7 hours) → hours. */
    public static function hours(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return max(0.0, round((float)$v, 2));
        $s = mb_strtolower(trim(str_replace(',', '.', (string)$v)));
        if (preg_match('/^(\d+)\s*h\s*(\d{1,2})\s*(m|min)?$/u', $s, $m)) return round((int)$m[1] + (int)$m[2] / 60, 2); // 1h30
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hours?|heures?|m|min|mins|minutes?|d|j|days?|jours?)?$/u', $s, $m)) throw new \InvalidArgumentException("unclear workload \"$v\": use hours (3h), minutes (45m) or days (2d)");
        $n = (float)$m[1]; $u = $m[2] ?? 'h';
        return round(match (true) { str_starts_with($u, 'm') => $n / 60, in_array($u[0], ['d', 'j'], true) => $n * self::HOURS_PER_DAY, default => $n }, 2);
    }

    private static function date(mixed $v, string $what): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string)$v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4))) throw new \InvalidArgumentException("$what must be a date YYYY-MM-DD");
        return $s;
    }

    /** Validated column values from user/assistant input (only the keys given). */
    private function values(array $d): array
    {
        $v = [];
        if (array_key_exists('title', $d)) { $v['title'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$d['title'])), 0, 200); if ($v['title'] === '') throw new \InvalidArgumentException('A task needs a title.'); }
        if (array_key_exists('body', $d)) $v['body'] = mb_substr(str_replace("\r\n", "\n", (string)$d['body']), 0, 50000);
        if (array_key_exists('status', $d)) { if (!isset(self::STATUSES[$d['status']])) throw new \InvalidArgumentException('status is one of: ' . implode(', ', array_keys(self::STATUSES))); $v['status'] = $d['status']; }
        if (array_key_exists('priority', $d)) { $p = (int)preg_replace('/\D/', '', (string)$d['priority']); if ($p < 1 || $p > 5) throw new \InvalidArgumentException('priority is 1 (most urgent) to 5'); $v['priority'] = $p; }
        if (array_key_exists('start_date', $d)) $v['start_date'] = self::date($d['start_date'], 'start');
        if (array_key_exists('due_date', $d)) $v['due_date'] = self::date($d['due_date'], 'due');
        if (array_key_exists('estimate_hours', $d)) $v['estimate_hours'] = self::hours($d['estimate_hours']);
        if (array_key_exists('tags', $d)) $v['tags'] = Store::tagString(is_array($d['tags']) ? $d['tags'] : (string)$d['tags']);
        if (array_key_exists('in_calendar', $d)) $v['in_calendar'] = $d['in_calendar'] && $d['in_calendar'] !== 'false' ? 1 : 0;
        if (array_key_exists('links', $d)) $v['links'] = json_encode(array_values(array_filter((array)$d['links'], 'is_array')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (array_key_exists('space', $d)) { $s = (int)$d['space']; if (!$this->app->spaces()->get($s)) throw new \InvalidArgumentException('no such space'); $v['space'] = $s; }
        if (isset($v['start_date'], $v['due_date']) && $v['start_date'] > $v['due_date']) throw new \InvalidArgumentException('The start is after the due date.');
        return $v;
    }

    // ---------- writing ----------

    /** $who: "you" (the app) or "assistant in <space>". */
    public function create(array $d, int $space, string $who = 'you'): array
    {
        $d += ['status' => 'todo', 'priority' => 3];
        $v = $this->values($d + ['space' => $space]);
        if (!isset($v['title'])) throw new \InvalidArgumentException('A task needs a title.');
        $st = $this->app->store;
        $space = $v['space'];
        // new cards go after the cards of the same or higher priority in their column
        $v['rank'] = (float)$st->q('SELECT COALESCE(MAX(rank), 0) FROM cards WHERE space = ? AND status = ? AND priority <= ? AND deleted_at IS NULL', [$space, $v['status'], $v['priority']])->fetchColumn() + 1;
        $st->q('UPDATE cards SET rank = rank + 1 WHERE space = ? AND status = ? AND rank >= ? AND deleted_at IS NULL', [$space, $v['status'], $v['rank']]);
        $v += ['code' => $this->nextCode($space), 'created' => time(), 'updated' => time(), 'created_by' => $who];
        if ($v['status'] === 'done') $v['done_at'] = time();
        $st->q('INSERT INTO cards(' . implode(',', array_keys($v)) . ') VALUES(' . implode(',', array_fill(0, count($v), '?')) . ')', array_values($v));
        $id = (int)$st->db->lastInsertId();
        if (!empty($d['depends_on'])) $this->setDeps($id, (array)$d['depends_on']);
        $this->log($id, $who, 'create', 'created', null);
        return $this->get($id);
    }

    /**
     * Change fields. $version: the version the editor loaded (refused if the card changed since).
     * Dependencies: depends_on (replace the list), add_depends_on / remove_depends_on (codes or ids).
     */
    public function update(int $id, array $d, string $who = 'you', ?int $version = null): array
    {
        $c = $this->get($id) ?? throw new \InvalidArgumentException("no task $id");
        if ($c['deleted_at']) throw new \InvalidArgumentException("{$c['code']} is in the trash: restore it first.");
        if ($version !== null && $version !== $c['version']) throw new Conflict("{$c['code']} was changed meanwhile" . ($this->lastWho($id) !== 'you' ? ' by the assistant' : '') . ': reload it.');
        $v = $this->values(array_intersect_key($d, array_flip(self::FIELDS)));
        $start = $v['start_date'] ?? (array_key_exists('start_date', $v) ? null : $c['start_date']);
        $due = $v['due_date'] ?? (array_key_exists('due_date', $v) ? null : $c['due_date']);
        if ($start && $due && $start > $due) throw new \InvalidArgumentException('The start is after the due date.');
        $before = $this->snapshot($id);
        $changes = [];
        foreach ($v as $k => $val) {
            $old = $before['row'][$k];
            if ((string)$old !== (string)$val) $changes[] = $this->describe($k, $old, $val);
            else unset($v[$k]);
        }
        if (isset($v['status'])) {
            $v['done_at'] = $v['status'] === 'done' ? time() : null;
            $v['rank'] = (float)$this->app->store->q('SELECT COALESCE(MAX(rank), 0) + 1 FROM cards WHERE space = ? AND status = ? AND deleted_at IS NULL', [$v['space'] ?? $c['space'], $v['status']])->fetchColumn();
        }
        $depsChanged = false;
        if (array_key_exists('depends_on', $d) || !empty($d['add_depends_on']) || !empty($d['remove_depends_on'])) {
            $ids = array_key_exists('depends_on', $d) ? $this->resolve((array)$d['depends_on']) : $c['depends_on'];
            $ids = array_values(array_unique(array_merge($ids, $this->resolve((array)($d['add_depends_on'] ?? [])))));
            $ids = array_values(array_diff($ids, $this->resolve((array)($d['remove_depends_on'] ?? []))));
            sort($ids); $old = $c['depends_on']; sort($old);
            if ($ids !== $old) { $this->setDeps($id, $ids); $depsChanged = true; $changes[] = 'dependencies: ' . ($ids ? implode(', ', array_map(fn($i) => $this->get($i)['code'] ?? "#$i", $ids)) : 'none'); }
        }
        if (!$v && !$depsChanged) return $c;
        if ($v) {
            $v['updated'] = time();
            $this->app->store->q('UPDATE cards SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($v))) . ', version = version + 1 WHERE id = ?', [...array_values($v), $id]);
        } else {
            $this->app->store->q('UPDATE cards SET updated = ?, version = version + 1 WHERE id = ?', [time(), $id]);
        }
        $this->log($id, $who, 'update', implode('; ', $changes), $before);
        return $this->get($id);
    }

    private function describe(string $k, mixed $old, mixed $new): string
    {
        return match ($k) {
            'body' => 'description edited',
            'status' => 'moved to ' . self::STATUSES[$new],
            'priority' => "priority P$old → P$new",
            'tags' => 'tags: ' . (implode(', ', Store::tagList((string)$new)) ?: 'none'),
            'space' => 'moved to space ' . ($this->app->spaces()->get((int)$new)['name'] ?? $new),
            'in_calendar' => $new ? 'shown in the calendar' : 'hidden from the calendar',
            'estimate_hours' => 'workload ' . ($new === null ? 'removed' : self::fmtHours((float)$new)),
            'links' => 'links changed',
            default => str_replace('_', ' ', $k) . ': ' . ($new === null || $new === '' ? 'none' : (is_string($new) ? mb_substr($new, 0, 80) : $new)),
        };
    }

    public static function fmtHours(?float $h): string
    {
        if ($h === null) return '';
        if ($h >= self::HOURS_PER_DAY && fmod($h, self::HOURS_PER_DAY) == 0) return ($h / self::HOURS_PER_DAY) . 'd';
        if ($h < 1) return round($h * 60) . 'm';
        return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . 'h';
    }

    /** Move within/between columns: put $id before $beforeId (or at the end), in $status. */
    public function move(int $id, string $status, ?int $beforeId = null, string $who = 'you'): array
    {
        $c = $this->get($id) ?? throw new \InvalidArgumentException("no task $id");
        if (!isset(self::STATUSES[$status])) throw new \InvalidArgumentException('unknown status');
        $st = $this->app->store;
        $before = $this->snapshot($id);
        $col = array_values(array_filter($this->list(['space' => $c['space'], 'status' => $status]), fn($x) => $x['id'] !== $id));
        $pos = count($col);
        foreach ($col as $i => $x) if ($x['id'] === $beforeId) { $pos = $i; break; }
        array_splice($col, $pos, 0, [['id' => $id]]);
        foreach ($col as $i => $x) $st->q('UPDATE cards SET rank = ? WHERE id = ?', [$i + 1, $x['id']]);
        $fields = ['updated' => time()];
        if ($status !== $c['status']) { $fields['status'] = $status; $fields['done_at'] = $status === 'done' ? time() : null; }
        $st->q('UPDATE cards SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($fields))) . ', version = version + 1 WHERE id = ?', [...array_values($fields), $id]);
        $this->log($id, $who, 'move', $status !== $c['status'] ? 'moved to ' . self::STATUSES[$status] : 'reordered', $before);
        return $this->get($id);
    }

    /** Put these cards first, in this order (in their columns); the others keep their order after them. */
    public function reorder(array $refs, string $who = 'you'): array
    {
        $ids = $this->resolve($refs);
        $byCol = [];
        foreach ($ids as $id) { $c = $this->get($id); if ($c) $byCol[$c['space'] . '|' . $c['status']][] = $id; }
        foreach ($byCol as $key => $first) {
            [$space, $status] = explode('|', $key);
            $rest = array_values(array_diff(array_column($this->list(['space' => (int)$space, 'status' => $status]), 'id'), $first));
            foreach ([...$first, ...$rest] as $i => $id) $this->app->store->q('UPDATE cards SET rank = ? WHERE id = ?', [$i + 1, $id]);
        }
        foreach ($ids as $id) $this->log($id, $who, 'move', 'reordered', null);
        return $ids;
    }

    /** General: the overall order of open cards across spaces (cards not listed keep coming after). */
    public function setGlobalOrder(array $refs, string $who = 'you'): void
    {
        $ids = $this->resolve($refs);
        $st = $this->app->store;
        $st->db->beginTransaction();
        $rest = array_values(array_diff(array_column(array_filter($this->list(['open' => true, 'global' => true]), fn($c) => $c['global_rank'] !== null), 'id'), $ids));
        foreach ([...$ids, ...$rest] as $i => $id) $st->q('UPDATE cards SET global_rank = ? WHERE id = ?', [$i + 1, $id]);
        $st->db->commit();
        foreach ($ids as $id) $this->log($id, $who, 'priority', 'overall order set to ' . (array_search($id, $ids, true) + 1), null);
    }

    public function clearGlobalRank(int $id, string $who = 'you'): void
    {
        $this->app->store->q('UPDATE cards SET global_rank = NULL WHERE id = ?', [$id]);
        $this->log($id, $who, 'priority', 'removed from the overall order', null);
    }

    /** To the trash (restorable for 30 days). Returns the codes of cards that depended on it. */
    public function delete(int $id, string $who = 'you'): array
    {
        $c = $this->get($id) ?? throw new \InvalidArgumentException("no task $id");
        if ($c['deleted_at']) return [];
        $before = $this->snapshot($id);
        $this->app->store->q('UPDATE cards SET deleted_at = ?, updated = ?, version = version + 1 WHERE id = ?', [time(), time(), $id]);
        $this->log($id, $who, 'delete', 'moved to the trash', $before);
        return array_values(array_filter(array_map(fn($i) => ($x = $this->get($i)) && !$x['deleted_at'] ? $x['code'] : null, $c['blocks'])));
    }

    public function restore(int $id, string $who = 'you'): array
    {
        $before = $this->snapshot($id);
        $this->app->store->q('UPDATE cards SET deleted_at = NULL, updated = ?, version = version + 1 WHERE id = ?', [time(), $id]);
        $this->log($id, $who, 'restore', 'restored from the trash', $before);
        return $this->get($id);
    }

    /** Worker: forget what has been in the trash for 30 days. */
    public function purge(int $days = 30): int
    {
        $st = $this->app->store;
        $ids = $st->q('SELECT id FROM cards WHERE deleted_at < ?', [time() - $days * 86400])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $st->q('DELETE FROM card_deps WHERE card = ? OR depends_on = ?', [$id, $id]);
            $st->q('DELETE FROM card_log WHERE card = ?', [$id]);
            $st->q('DELETE FROM card_time WHERE card = ?', [$id]);
            $st->q('DELETE FROM cards WHERE id = ?', [$id]);
        }
        return count($ids);
    }

    // ---------- dependencies ----------

    /** @return int[] ids for codes / ids */
    public function resolve(array $refs): array
    {
        $out = [];
        foreach ($refs as $r) {
            if ($r === null || $r === '') continue;
            $c = is_int($r) ? $this->get($r) : $this->find((string)$r);
            if (!$c) throw new \InvalidArgumentException("no task $r");
            $out[] = $c['id'];
        }
        return array_values(array_unique($out));
    }

    private function setDeps(int $id, array $refs): void
    {
        $ids = $this->resolve($refs);
        if (in_array($id, $ids, true)) throw new \InvalidArgumentException('A task cannot depend on itself.');
        foreach ($ids as $dep) {
            if ($path = $this->path($dep, $id)) { // $dep already (indirectly) depends on $id
                $codes = array_map(fn($i) => $this->get($i)['code'], [$id, ...$path]);
                throw new \InvalidArgumentException('That would make a loop: ' . implode(' → ', [...$codes, $codes[0]]) . ' (each waits for the next).');
            }
        }
        $st = $this->app->store;
        $st->q('DELETE FROM card_deps WHERE card = ?', [$id]);
        foreach ($ids as $dep) $st->q('INSERT OR IGNORE INTO card_deps(card, depends_on) VALUES(?,?)', [$id, $dep]);
    }

    /** A dependency path from $from to $to ([$from, ..., $to]), or [] (depth-first). */
    private function path(int $from, int $to, array $seen = []): array
    {
        if ($from === $to) return [$to];
        $seen[$from] = true;
        foreach ($this->app->store->q('SELECT depends_on FROM card_deps WHERE card = ?', [$from])->fetchAll(\PDO::FETCH_COLUMN) as $next) {
            $next = (int)$next;
            if (isset($seen[$next])) continue;
            if ($p = $this->path($next, $to, $seen)) return [$from, ...$p];
        }
        return [];
    }

    /** What doesn't add up: overdue, waiting on unfinished tasks, due before what it waits for, depends on a deleted task. */
    public function warnings(array $c): array
    {
        $w = [];
        if ($c['status'] === 'done' || $c['deleted_at']) return $w;
        $today = date('Y-m-d');
        if ($c['due_date'] && $c['due_date'] < $today) $w[] = ['kind' => 'overdue', 'text' => 'Overdue since ' . $c['due_date']];
        foreach ($c['depends_on'] as $d) {
            $x = $this->get($d);
            if (!$x) continue;
            if ($x['deleted_at']) { $w[] = ['kind' => 'deleted', 'text' => "Waits for {$x['code']}, which is in the trash"]; continue; }
            if ($x['status'] !== 'done') {
                $w[] = ['kind' => 'waiting', 'text' => "Waits for {$x['code']} ({$x['title']})"];
                if ($c['due_date'] && $x['due_date'] && $x['due_date'] > $c['due_date']) $w[] = ['kind' => 'dates', 'text' => "Due {$c['due_date']}, but {$x['code']} is only due {$x['due_date']}"];
            }
        }
        return $w;
    }

    // ---------- time ----------

    public function addTime(int $id, mixed $hours, ?string $day = null, string $note = '', string $who = 'you'): array
    {
        $c = $this->need($id);
        $h = self::hours($hours);
        if (!$h) throw new \InvalidArgumentException('How much time?');
        $day = self::date($day ?: date('Y-m-d'), 'day');
        $this->app->store->q('INSERT INTO card_time(card, day, hours, note, created) VALUES(?,?,?,?,?)', [$id, $day, $h, mb_substr($note, 0, 300), time()]);
        $this->log($id, $who, 'time', self::fmtHours($h) . " spent on $day" . ($note !== '' ? ": $note" : ''), null);
        return $this->get($c['id']);
    }

    public function timeEntries(int $id): array
    {
        return $this->app->store->q('SELECT id, day, hours, note FROM card_time WHERE card = ? ORDER BY day DESC, id DESC', [$id])->fetchAll();
    }

    public function deleteTime(int $entry): void { $this->app->store->q('DELETE FROM card_time WHERE id = ?', [$entry]); }

    // ---------- history ----------

    private function snapshot(int $id): array
    {
        $row = $this->app->store->q('SELECT * FROM cards WHERE id = ?', [$id])->fetch();
        return ['row' => $row, 'deps' => array_map('intval', $this->app->store->q('SELECT depends_on FROM card_deps WHERE card = ?', [$id])->fetchAll(\PDO::FETCH_COLUMN))];
    }

    private function log(int $id, string $who, string $action, string $what, ?array $before): void
    {
        $this->app->store->q('INSERT INTO card_log(card, ts, who, action, what, before) VALUES(?,?,?,?,?,?)',
            [$id, time(), $who, $action, $what, $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    }

    private function lastWho(int $id): string { return (string)$this->app->store->q('SELECT who FROM card_log WHERE card = ? ORDER BY id DESC LIMIT 1', [$id])->fetchColumn(); }

    public function history(int $id): array
    {
        return array_map(fn($r) => ['id' => (int)$r['id'], 'ts' => (int)$r['ts'], 'who' => $r['who'], 'action' => $r['action'], 'what' => $r['what'],
            'can_undo' => $r['before'] !== null && !$r['undone']], $this->app->store->q('SELECT * FROM card_log WHERE card = ? ORDER BY id DESC LIMIT 100', [$id])->fetchAll());
    }

    /** Recent changes on all cards (or one space), for "what did the assistant change?". */
    public function recent(?int $space = null, int $limit = 50): array
    {
        return $this->app->store->q('SELECT l.id, l.card, l.ts, l.who, l.action, l.what, c.code, c.title, c.space FROM card_log l JOIN cards c ON c.id = l.card'
            . ($space !== null ? ' WHERE c.space = ?' : '') . ' ORDER BY l.id DESC LIMIT ?', $space !== null ? [$space, $limit] : [$limit])->fetchAll();
    }

    /** Put the card back as it was before this change. */
    public function undo(int $logId, string $who = 'you'): array
    {
        $st = $this->app->store;
        $l = $st->q('SELECT * FROM card_log WHERE id = ?', [$logId])->fetch() ?: throw new \InvalidArgumentException('no such change');
        if ($l['before'] === null || $l['undone']) throw new \InvalidArgumentException('This change cannot be undone.');
        $b = json_decode($l['before'], true);
        $id = (int)$l['card'];
        $now = $this->snapshot($id);
        $row = array_diff_key($b['row'], array_flip(['id', 'code', 'created', 'version']));
        $row['updated'] = time();
        $st->db->beginTransaction();
        $st->q('UPDATE cards SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($row))) . ', version = version + 1 WHERE id = ?', [...array_values($row), $id]);
        $st->q('DELETE FROM card_deps WHERE card = ?', [$id]);
        foreach ($b['deps'] as $d) $st->q('INSERT OR IGNORE INTO card_deps(card, depends_on) VALUES(?,?)', [$id, $d]);
        $st->q('UPDATE card_log SET undone = 1 WHERE id = ?', [$logId]);
        $st->db->commit();
        $this->log($id, $who, 'undo', 'undid: ' . $l['what'], $now);
        return $this->get($id);
    }

    // ---------- for the assistant ----------

    /** One line per card, for tool results. */
    public function line(array $c, bool $withSpace = false): string
    {
        $bits = [self::STATUSES[$c['status']], "P{$c['priority']}"];
        if ($c['start_date']) $bits[] = "start {$c['start_date']}";
        if ($c['due_date']) $bits[] = "due {$c['due_date']}";
        if ($c['estimate_hours'] !== null) $bits[] = 'workload ' . self::fmtHours($c['estimate_hours']) . ($c['spent_hours'] ? ', spent ' . self::fmtHours($c['spent_hours']) : '');
        if ($c['checklist']['total']) $bits[] = "checklist {$c['checklist']['done']}/{$c['checklist']['total']}";
        if ($c['tags']) $bits[] = '#' . implode(' #', $c['tags']);
        if ($c['depends_on']) $bits[] = 'waits for ' . implode(', ', array_map(fn($i) => $this->get($i)['code'] ?? "#$i", $c['depends_on']));
        if ($withSpace) $bits[] = 'space ' . ($this->app->spaces()->get($c['space'])['name'] ?? $c['space']);
        if ($c['global_rank'] !== null) $bits[] = 'overall #' . (int)$c['global_rank'];
        if ($c['in_calendar']) $bits[] = 'in calendar';
        $warn = array_column($this->warnings($c), 'text');
        return "{$c['code']} {$c['title']} — " . implode(', ', $bits) . ($warn ? ' ⚠ ' . implode('; ', $warn) : '');
    }

    public function describeCard(array $c): string
    {
        $out = $this->line($c, true) . "\n";
        if (trim($c['body']) !== '') $out .= "\n" . $c['body'] . "\n";
        if ($c['blocks']) $out .= "\nBlocks: " . implode(', ', array_map(fn($i) => ($x = $this->get($i)) ? "{$x['code']} {$x['title']}" : "#$i", $c['blocks'])) . "\n";
        if ($c['links']) $out .= "\nLinks: " . implode(', ', array_map(fn($l) => ($l['label'] ?? '') . ' (' . ($l['type'] ?? '') . ' ' . ($l['ref'] ?? '') . ')', $c['links'])) . "\n";
        return trim($out);
    }
}

/** Someone else changed the record since it was loaded. */
final class Conflict extends \RuntimeException {}
