<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The personal knowledge base: every file lives in a folder (/Inbox, /Meetings, /Clients/Alice...).
 * Folders are logical (stored in the database); files stay on disk under files/Y/m. Everything with
 * text is indexed in the same hybrid search as the conversations, and can be searched per folder.
 */
final class Library
{
    public const INBOX = '/Inbox';
    public const MEETINGS = '/Meetings';
    public const SCREENSHOTS = '/Screenshots';
    public const NOTES = '/Notes';

    public function __construct(private App $app) {}

    /** Clean a folder path: '/A/B', no '.', '..', empty or control parts; '' and '/' are the root. */
    public static function normalize(string $path): string
    {
        $parts = [];
        foreach (preg_split('#[/\\\\]+#', $path) as $p) {
            $p = trim(preg_replace('/[\x00-\x1f\x7f]+/', '', $p));
            if ($p === '' || $p === '.' || $p === '..') continue;
            $parts[] = mb_substr($p, 0, 80);
        }
        return '/' . implode('/', array_slice($parts, 0, 12));
    }

    public static function parent(string $path): string { return $path === '/' ? '/' : self::normalize(dirname($path)); }

    public static function join(string $folder, string $name): string { return rtrim($folder, '/') . '/' . $name; }

    /** Label used in search results and citations, e.g. "/Clients/Alice/contract.pdf #12". */
    public static function label(array $f): string { return self::join((string)($f['folder'] ?? self::INBOX), (string)$f['name']) . " #{$f['id']}"; }

    /** SQL condition (and args) for "this folder and its subfolders" on files.folder. */
    public static function within(string $folder, string $col = 'folder'): array
    {
        if ($folder === '/') return ['1=1', []];
        return ["($col = ? OR $col LIKE ? ESCAPE '\\')", [$folder, addcslashes($folder, '%_\\') . '/%']];
    }

    public function ensureFolder(string $path): void
    {
        $path = self::normalize($path);
        while ($path !== '/') {
            $this->app->store->q('INSERT OR IGNORE INTO folders(path, created) VALUES(?,?)', [$path, time()]);
            $path = self::parent($path);
        }
    }

    public function exists(string $path): bool
    {
        $path = self::normalize($path);
        if ($path === '/') return true;
        [$w, $a] = self::within($path);
        return (bool)$this->app->store->q('SELECT 1 FROM folders WHERE path = ?', [$path])->fetchColumn()
            || (bool)$this->app->store->q("SELECT 1 FROM files WHERE $w LIMIT 1", $a)->fetchColumn();
    }

    /** All folders with their file counts (direct, and including subfolders). */
    public function tree(): array
    {
        $st = $this->app->store;
        $paths = array_column($st->q('SELECT path FROM folders')->fetchAll(), 'path');
        $direct = [];
        foreach ($st->q('SELECT folder, COUNT(*) n FROM files GROUP BY folder')->fetchAll() as $r) {
            $direct[$r['folder']] = (int)$r['n'];
            $paths[] = $r['folder'];
        }
        foreach ([self::INBOX, self::MEETINGS, self::NOTES] as $d) $paths[] = $d;
        $all = [];
        foreach ($paths as $p) for ($p = self::normalize($p); $p !== '/'; $p = self::parent($p)) $all[$p] = true;
        $all = array_keys($all);
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);
        return array_map(function (string $p) use ($direct) {
            $total = 0;
            foreach ($direct as $f => $n) if ($f === $p || str_starts_with($f, "$p/")) $total += $n;
            return ['path' => $p, 'name' => basename($p), 'depth' => substr_count($p, '/'), 'files' => $direct[$p] ?? 0, 'total' => $total];
        }, $all);
    }

    /** Direct subfolders and files of a folder. */
    public function list(string $folder): array
    {
        $folder = self::normalize($folder);
        $subs = [];
        foreach ($this->tree() as $t) if ($t['path'] !== '/' && self::parent($t['path']) === $folder) $subs[] = $t;
        $files = $this->app->store->q('SELECT id, name, mime, kind, size, duration, created, folder, status, note, LENGTH(text) chars FROM files WHERE folder = ? ORDER BY name COLLATE NOCASE', [$folder])->fetchAll();
        return ['folder' => $folder, 'folders' => $subs, 'files' => array_map(fn($f) => ['id' => (int)$f['id'], 'size' => (int)$f['size'], 'note' => (bool)$f['note'], 'chars' => (int)$f['chars'], 'created' => (int)$f['created']] + $f, $files)];
    }

    public function createFolder(string $path): string
    {
        $path = self::normalize($path);
        if ($path === '/') throw new \InvalidArgumentException('folder name required');
        $this->ensureFolder($path);
        return $path;
    }

    /** Rename or move a folder (with its subfolders and files). */
    public function moveFolder(string $from, string $to): string
    {
        $from = self::normalize($from); $to = self::normalize($to);
        if ($from === '/' || $to === '/') throw new \InvalidArgumentException('the root folder cannot be moved');
        if ($to === $from) return $to;
        if (str_starts_with("$to/", "$from/")) throw new \InvalidArgumentException('a folder cannot be moved into itself');
        if (!$this->exists($from)) throw new \InvalidArgumentException("no folder $from");
        if ($this->exists($to)) throw new \InvalidArgumentException("$to already exists");
        $st = $this->app->store;
        $st->db->beginTransaction();
        try {
            $len = mb_strlen($from);
            foreach (['folders' => 'path', 'files' => 'folder'] as $table => $col) {
                [$w, $a] = self::within($from, $col);
                $st->q("UPDATE $table SET $col = ? || substr($col, ?) WHERE $w", [$to, $len + 1, ...$a]);
            }
            $this->ensureFolder($to);
            [$w, $a] = self::within($to);
            foreach ($st->q("SELECT id, name, folder FROM files WHERE $w", $a)->fetchAll() as $f) $this->relabel($f);
            $st->db->commit();
        } catch (\Throwable $e) {
            $st->db->rollBack();
            throw $e;
        }
        return $to;
    }

    /** Delete a folder; refuses when it still has files unless $recursive. Returns the number of files deleted. */
    public function deleteFolder(string $path, bool $recursive = false): int
    {
        $path = self::normalize($path);
        if ($path === '/') throw new \InvalidArgumentException('the root folder cannot be deleted');
        [$w, $a] = self::within($path);
        $ids = array_column($this->app->store->q("SELECT id FROM files WHERE $w", $a)->fetchAll(), 'id');
        if ($ids && !$recursive) throw new \InvalidArgumentException("$path is not empty (" . count($ids) . ' files)');
        foreach ($ids as $id) $this->deleteFile((int)$id);
        [$w, $a] = self::within($path, 'path');
        $this->app->store->q("DELETE FROM folders WHERE $w", $a);
        return count($ids);
    }

    public function moveFile(int $id, string $folder): array
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        $folder = self::normalize($folder);
        $this->ensureFolder($folder);
        $this->app->store->q('UPDATE files SET folder = ? WHERE id = ?', [$folder, $id]);
        $this->relabel(['folder' => $folder] + $f);
        return $this->app->files()->get($id);
    }

    public function renameFile(int $id, string $name): array
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        $name = mb_substr(preg_replace('/[^\p{L}\p{N}._ ()&,+\'-]+/u', '_', trim(basename($name))), 0, 120);
        if ($name === '' || $name === '.' || $name === '..') throw new \InvalidArgumentException('invalid name');
        $this->app->store->q('UPDATE files SET name = ? WHERE id = ?', [$name, $id]);
        $this->relabel(['name' => $name] + $f);
        return $this->app->files()->get($id);
    }

    public function deleteFile(int $id): void
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        $this->app->store->deleteDocs(['file', 'transcript'], $id);
        $this->app->store->q('DELETE FROM files WHERE id = ?', [$id]);
        if ($f['path'] !== '' && is_file($f['path'])) @unlink($f['path']);
    }

    /** Create or update a Markdown note. */
    public function saveNote(string $folder, string $name, string $content, ?int $id = null): array
    {
        $files = $this->app->files();
        if ($id !== null) {
            $f = $files->get($id) ?? throw new \InvalidArgumentException("no file $id");
            if (!$f['note'] && $f['kind'] !== 'text') throw new \InvalidArgumentException('only notes and text files can be edited');
            file_put_contents($f['path'], $content);
            $this->app->store->q("UPDATE files SET size = ?, text = NULL, status = 'pending' WHERE id = ?", [strlen($content), $id]);
            if ($name !== '' && $name !== $f['name']) $this->renameFile($id, $name);
        } else {
            $name = trim($name) !== '' ? trim($name) : 'Note ' . date('Y-m-d H.i');
            if (!preg_match('/\.(md|txt)$/i', $name)) $name .= '.md';
            $tmp = tempnam(sys_get_temp_dir(), 'note');
            file_put_contents($tmp, $content);
            $id = $files->save($tmp, $name, 'text/markdown', $folder);
            $this->app->store->q('UPDATE files SET note = 1 WHERE id = ?', [$id]);
        }
        $files->text($id); // small: index right away
        return $files->get($id);
    }

    /** Search the library (optionally one folder and its subfolders). */
    public function search(string $query, string $folder = '/', int $limit = 10): array
    {
        $folder = self::normalize($folder);
        $hits = $this->app->recall()->search($query, $limit, PHP_INT_MAX, ['file', 'transcript'], $folder === '/' ? null : $this->fileIds($folder));
        $out = [];
        foreach ($hits as $h) {
            $f = $this->app->files()->get((int)$h['ref']);
            if (!$f) continue;
            $out[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'folder' => $f['folder'], 'kind' => $f['kind'], 'snippet' => preg_replace('/\s+/', ' ', mb_substr($h['text'], 0, 400)),
                'score' => round($h['score'], 4), 'sim' => $h['sim'] !== null ? round($h['sim'], 3) : null];
        }
        return $out;
    }

    /** @return int[] */
    public function fileIds(string $folder): array
    {
        [$w, $a] = self::within(self::normalize($folder));
        return array_map('intval', array_column($this->app->store->q("SELECT id FROM files WHERE $w", $a)->fetchAll(), 'id'));
    }

    public function stats(): array
    {
        $r = $this->app->store->q("SELECT COUNT(*) n, COALESCE(SUM(size),0) bytes, SUM(status='pending') pending, SUM(status='failed') failed FROM files")->fetch();
        return ['files' => (int)$r['n'], 'bytes' => (int)$r['bytes'], 'pending' => (int)$r['pending'], 'failed' => (int)$r['failed']];
    }

    private function relabel(array $f): void
    {
        $this->app->store->q("UPDATE docs SET label = ? WHERE kind IN ('file','transcript') AND ref = ?", [self::label($f), $f['id']]);
    }
}
