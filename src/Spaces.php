<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Spaces: separate conversations (a client, the house, health...) with their own library folder,
 * instructions, notes, default tier and colour. Space 0 is "General" (chat apps write there).
 * A space does not see the others' conversations unless asked; the library and what the assistant
 * knows about the user (USER.md, MEMORY.md, skills) are shared.
 */
final class Spaces
{
    public const COLORS = ['#0e7c6b', '#2563eb', '#7c3aed', '#c026d3', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#475569'];

    public function __construct(private App $app) {}

    public function general(): array
    {
        return ['id' => 0, 'name' => 'General', 'slug' => 'general', 'folder' => '', 'instructions' => '', 'notes' => '', 'tier' => 'standard', 'color' => '', 'archived' => 0];
    }

    public function get(int $id): ?array
    {
        if ($id === 0) return $this->general();
        return $this->app->store->q('SELECT * FROM spaces WHERE id = ?', [$id])->fetch() ?: null;
    }

    /** By id, slug or name (case-insensitive). */
    public function find(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || in_array(mb_strtolower($key), ['general', 'général', 'general', '0'], true)) return $this->general();
        if (ctype_digit($key)) return $this->get((int)$key);
        return $this->app->store->q('SELECT * FROM spaces WHERE slug = ? OR name = ? COLLATE NOCASE', [self::slug($key), $key])->fetch() ?: null;
    }

    /** All spaces with their unread count (General first). */
    public function all(bool $archived = false): array
    {
        $st = $this->app->store;
        $rows = $st->q('SELECT * FROM spaces' . ($archived ? '' : ' WHERE archived = 0') . ' ORDER BY name COLLATE NOCASE')->fetchAll();
        $out = [];
        foreach ([$this->general() + ['last_read' => (int)$st->get('space_read_0', '0')], ...$rows] as $s) {
            $s['id'] = (int)$s['id'];
            $last = $s['id'] === 0 ? (int)$st->get('space_read_0', '0') : (int)$s['last_read'];
            $s['unread'] = (int)$st->q("SELECT COUNT(*) FROM messages WHERE space = ? AND id > ? AND role = 'assistant'", [$s['id'], $last])->fetchColumn();
            $s['last_ts'] = (int)$st->q('SELECT COALESCE(MAX(ts),0) FROM messages WHERE space = ?', [$s['id']])->fetchColumn();
            $out[] = $s;
        }
        return $out;
    }

    public function save(array $d, ?int $id = null): array
    {
        $name = trim(mb_substr((string)($d['name'] ?? ''), 0, 60));
        if ($name === '') throw new \InvalidArgumentException('Give the space a name.');
        $slug = self::slug($name);
        if (in_array($slug, ['general', 'all'], true)) throw new \InvalidArgumentException('That name is reserved.');
        $dup = $this->app->store->q('SELECT id FROM spaces WHERE slug = ? AND id != ?', [$slug, $id ?? 0])->fetchColumn();
        if ($dup) throw new \InvalidArgumentException("A space called \"$name\" already exists.");
        $folder = trim((string)($d['folder'] ?? '')) === '' ? '' : Library::normalize((string)$d['folder']);
        if ($folder === '/') $folder = '';
        if ($folder !== '') $this->app->library()->ensureFolder($folder);
        $tier = in_array($d['tier'] ?? '', Tier::NAMES, true) ? $d['tier'] : 'standard';
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($d['color'] ?? '')) ? strtolower($d['color']) : self::COLORS[random_int(0, count(self::COLORS) - 1)];
        $fields = ['name' => $name, 'slug' => $slug, 'folder' => $folder, 'instructions' => mb_substr((string)($d['instructions'] ?? ''), 0, 8000),
            'tier' => $tier, 'color' => $color];
        if (array_key_exists('notes', $d)) $fields['notes'] = mb_substr((string)$d['notes'], 0, 20000);
        if (array_key_exists('archived', $d)) $fields['archived'] = $d['archived'] ? 1 : 0;
        $st = $this->app->store;
        if ($id === null) {
            $fields['created'] = time();
            $st->q('INSERT INTO spaces(' . implode(',', array_keys($fields)) . ') VALUES(' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
            $id = (int)$st->db->lastInsertId();
        } else {
            if (!$this->get($id) || $id === 0) throw new \InvalidArgumentException('no such space');
            $st->q('UPDATE spaces SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($fields))) . ' WHERE id = ?', [...array_values($fields), $id]);
        }
        return $this->get($id);
    }

    /** Delete a space and its conversation (its documents stay in the library). */
    public function delete(int $id): void
    {
        if ($id === 0 || !$this->get($id)) throw new \InvalidArgumentException('no such space');
        $st = $this->app->store;
        $ids = array_column($st->q('SELECT id FROM messages WHERE space = ?', [$id])->fetchAll(), 'id');
        $st->deleteMessages($ids);
        foreach ($st->q('SELECT id FROM summaries WHERE space = ?', [$id])->fetchAll() as $r) $st->deleteDocs(['summary'], (int)$r['id']);
        $st->q('DELETE FROM summaries WHERE space = ?', [$id]);
        $st->q('UPDATE tasks SET space = 0 WHERE space = ?', [$id]);
        $st->q('DELETE FROM spaces WHERE id = ?', [$id]);
        $st->set(Store::summarizedKey($id), '0');
    }

    public function markRead(int $id): void
    {
        $max = (int)$this->app->store->q('SELECT COALESCE(MAX(id),0) FROM messages WHERE space = ?', [$id])->fetchColumn();
        if ($id === 0) $this->app->store->set('space_read_0', (string)$max);
        else $this->app->store->q('UPDATE spaces SET last_read = ? WHERE id = ?', [$max, $id]);
    }

    /** The space whose library folder contains this folder (deepest match), or null. */
    public function forFolder(string $folder): ?array
    {
        $best = null;
        foreach ($this->app->store->q("SELECT * FROM spaces WHERE folder != '' AND archived = 0")->fetchAll() as $s) {
            if (($folder === $s['folder'] || str_starts_with($folder, $s['folder'] . '/')) && (!$best || strlen($s['folder']) > strlen($best['folder']))) $best = $s;
        }
        return $best;
    }

    /** Which space a chat channel (Telegram, Signal, email, terminal) writes to; changed with "/space name". */
    public function forChannel(string $channel): int
    {
        $id = (int)$this->app->store->get("channel_space_$channel", '0');
        return $id && ($s = $this->get($id)) && !$s['archived'] ? $id : 0;
    }

    public function setForChannel(string $channel, int $id): void { $this->app->store->set("channel_space_$channel", (string)$id); }

    public static function slug(string $name): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        return $s !== '' ? substr($s, 0, 40) : 'space-' . substr(md5($name), 0, 6);
    }
}
