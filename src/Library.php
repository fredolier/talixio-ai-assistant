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
    public const FAQ = '/FAQ'; // the notes about Talixio itself, kept up to date by Faq::sync(): read-only
    public const TRASH_DAYS = 30; // deleted files can be restored this long

    /** Set while Faq::sync() writes the FAQ. */
    public static bool $system = false;

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
    /** Whether a folder is read-only (the FAQ and below). */
    public static function readonly(string $path): bool
    {
        $path = self::normalize($path);
        return $path === self::FAQ || str_starts_with($path, self::FAQ . '/');
    }

    /** Refuse changes in a read-only folder. */
    public static function guard(string ...$paths): void
    {
        if (self::$system) return;
        foreach ($paths as $p) if (self::readonly($p)) throw new \InvalidArgumentException('The FAQ is read-only: it is kept up to date with each version of Talixio. Copy a page into another folder to change it.');
    }

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
            || (bool)$this->app->store->q("SELECT 1 FROM files WHERE $w AND deleted_at IS NULL LIMIT 1", $a)->fetchColumn();
    }

    /** All folders with their file counts (direct, and including subfolders). */
    public function tree(): array
    {
        $st = $this->app->store;
        $paths = array_column($st->q('SELECT path FROM folders')->fetchAll(), 'path');
        $direct = [];
        foreach ($st->q('SELECT folder, COUNT(*) n FROM files WHERE deleted_at IS NULL GROUP BY folder')->fetchAll() as $r) {
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
            return ['path' => $p, 'name' => basename($p), 'depth' => substr_count($p, '/'), 'files' => $direct[$p] ?? 0, 'total' => $total, 'readonly' => self::readonly($p)];
        }, $all);
    }

    /** Direct subfolders and files of a folder. */
    public function list(string $folder): array
    {
        $folder = self::normalize($folder);
        $subs = [];
        foreach ($this->tree() as $t) if ($t['path'] !== '/' && self::parent($t['path']) === $folder) $subs[] = $t;
        $files = $this->app->store->q('SELECT id, name, mime, kind, size, duration, created, folder, status, note, suggestion, LENGTH(text) chars FROM files WHERE folder = ? AND deleted_at IS NULL ORDER BY name COLLATE NOCASE', [$folder])->fetchAll();
        $sugg = fn(?string $j) => ($d = json_decode((string)$j, true)) && !empty($d['folder']) ? $d : null; // only real suggestions, not done/dismissed
        return ['folder' => $folder, 'readonly' => self::readonly($folder), 'folders' => $subs, 'files' => array_map(fn($f) => ['id' => (int)$f['id'], 'size' => (int)$f['size'], 'note' => (bool)$f['note'], 'chars' => (int)$f['chars'],
            'created' => (int)$f['created'], 'suggestion' => $sugg($f['suggestion'])] + $f, $files)];
    }

    public function createFolder(string $path): string
    {
        $path = self::normalize($path);
        if ($path === '/') throw new \InvalidArgumentException('folder name required');
        self::guard($path);
        $this->ensureFolder($path);
        return $path;
    }

    /** Rename or move a folder (with its subfolders and files). */
    public function moveFolder(string $from, string $to): string
    {
        $from = self::normalize($from); $to = self::normalize($to);
        if ($from === '/' || $to === '/') throw new \InvalidArgumentException('the root folder cannot be moved');
        self::guard($from, $to);
        if ($to === $from) return $to;
        if (str_starts_with("$to/", "$from/")) throw new \InvalidArgumentException('a folder cannot be moved into itself');
        if (!$this->exists($from)) throw new \InvalidArgumentException("no folder $from");
        if ($this->exists($to)) throw new \InvalidArgumentException("$to already exists");
        $st = $this->app->store;
        $st->db->beginTransaction();
        try {
            $len = mb_strlen($from);
            foreach (['folders' => 'path', 'files' => 'folder', 'shares' => 'folder'] as $table => $col) { // links to a folder follow it
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

    /** Delete a folder; refuses when it still has files unless $recursive. Its files go to the trash. Returns their number. */
    public function deleteFolder(string $path, bool $recursive = false): int
    {
        $path = self::normalize($path);
        if ($path === '/') throw new \InvalidArgumentException('the root folder cannot be deleted');
        self::guard($path);
        [$w, $a] = self::within($path);
        $ids = array_column($this->app->store->q("SELECT id FROM files WHERE $w AND deleted_at IS NULL", $a)->fetchAll(), 'id');
        if ($ids && !$recursive) throw new \InvalidArgumentException("$path is not empty (" . count($ids) . ' files)');
        $now = time();
        foreach ($ids as $id) $this->trashFile((int)$id, $path, $now);
        [$w, $a] = self::within($path, 'path');
        $this->app->store->q("DELETE FROM folders WHERE $w", $a);
        return count($ids);
    }

    /**
     * Move a file. When its name is taken there: $replace true gives that file this content (see replaceWith),
     * false keeps both ("name (2).ext"), null asks (Conflict).
     */
    public function moveFile(int $id, string $folder, ?bool $replace = false): array
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        $folder = self::normalize($folder);
        self::guard((string)$f['folder'], $folder);
        if ($folder === $f['folder']) return $f;
        $name = (string)$f['name'];
        if (($other = $this->taken($folder, $name, (int)$f['id'])) !== null) {
            if ($replace === null) throw new Conflict("$folder already has a file named $name.");
            if ($replace) return $this->replaceWith($other, (int)$f['id']);
            $name = $this->freeName($folder, $name);
            $this->app->store->q('UPDATE files SET name = ? WHERE id = ?', [$name, $id]);
            $f['name'] = $name;
        }
        $this->ensureFolder($folder);
        $this->app->store->q('UPDATE files SET folder = ? WHERE id = ?', [$folder, $id]);
        $this->relabel(['folder' => $folder] + $f);
        return $this->app->files()->get($id);
    }

    /** Rename a file; a name taken in its folder: as for moveFile. */
    public function renameFile(int $id, string $name, ?bool $replace = false): array
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        self::guard((string)$f['folder']);
        $name = self::cleanName($name);
        if ($name === '' || $name === '.' || $name === '..') throw new \InvalidArgumentException('invalid name');
        if (($other = $this->taken((string)$f['folder'], $name, (int)$f['id'])) !== null) {
            if ($replace === null) throw new Conflict("{$f['folder']} already has a file named $name.");
            if ($replace) return $this->replaceWith($other, (int)$f['id']);
            $name = $this->freeName((string)$f['folder'], $name);
        }
        $this->app->store->q('UPDATE files SET name = ? WHERE id = ?', [$name, $id]);
        if ($f['kind'] === 'text' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION))) {
            // a text file follows its new extension: page.md renamed page.html is shown as a web page, no longer as a note
            $mime = self::textMime($name);
            $this->app->store->q('UPDATE files SET mime = ?, kind = ?, note = ? WHERE id = ?', [$mime, Files::kind($mime, $name), (int)self::isNoteName($name), $id]);
        }
        $this->relabel(['name' => $name] + $f);
        return $this->app->files()->get($id);
    }

    /** The file named $name in $folder (in any case), other than $except. */
    public function taken(string $folder, string $name, int $except = 0): ?int
    {
        $id = $this->app->store->q('SELECT id FROM files WHERE folder = ? AND name = ? COLLATE NOCASE AND id != ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [self::normalize($folder), $name, $except])->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** $name, or "name (2).ext", "name (3).ext"… : the first one free in $folder. */
    public function freeName(string $folder, string $name, int $except = 0): string
    {
        if ($this->taken($folder, $name, $except) === null) return $name;
        $ext = preg_match('/^(.+?)(\.[A-Za-z0-9]{1,10})$/', $name, $m) ? $m[2] : '';
        $base = preg_replace('/ \(\d+\)$/', '', $ext !== '' ? $m[1] : $name);
        for ($n = 2; ; $n++) if ($this->taken($folder, $cand = "$base ($n)$ext", $except) === null) return $cand;
    }

    /**
     * $target takes the content of $source (what it held becomes a version), and $source goes: its id, shares
     * and links now lead to $target. Unless $keep (a copy): then $source stays as it is.
     */
    public function replaceWith(int $target, int $source, bool $keep = false): array
    {
        $files = $this->app->files();
        $t = $files->get($target) ?? throw new \InvalidArgumentException("no file $target");
        $s = $files->get($source) ?? throw new \InvalidArgumentException("no file $source");
        self::guard((string)$t['folder']);
        if (!$keep) self::guard((string)$s['folder']);
        $tmp = tempnam(sys_get_temp_dir(), 'rep');
        if (!is_file($s['path']) || !copy($s['path'], $tmp)) throw new \RuntimeException('could not read the file');
        try { $files->replace($target, $tmp, (string)$s['mime']); }
        finally { if (is_file($tmp)) @unlink($tmp); }
        if ($s['note']) $this->app->store->q('UPDATE files SET note = 1 WHERE id = ?', [$target]);
        if (!$keep) $this->absorb($target, $s);
        return $files->get($target);
    }

    /** The row of $s goes, and its id leads to $target from now on (links in messages, shares, tasks…). */
    private function absorb(int $target, array $s): void
    {
        $st = $this->app->store; $id = (int)$s['id'];
        $st->deleteDocs(['file', 'transcript'], $id);
        $st->q('DELETE FROM files WHERE id = ?', [$id]);
        if (is_file((string)$s['path'])) @unlink($s['path']);
        $this->app->files()->dropPreviews($id);
        foreach ($this->app->files()->versions($id) as $v) $st->q('UPDATE file_versions SET file_id = ? WHERE id = ?', [$target, $v['id']]);
        $st->q('INSERT OR REPLACE INTO file_aliases(id, file_id) VALUES(?, ?)', [$id, $target]);
        $st->q('UPDATE file_aliases SET file_id = ? WHERE file_id = ?', [$target, $id]);
        $st->q('UPDATE shares SET file_id = ? WHERE file_id = ?', [$target, $id]);
    }

    /**
     * Once: files with the same name in the same folder (before 0.28.29 a name could be used twice) become one,
     * the newest; the others are kept as its versions. Read-only folders (the FAQ) look after themselves.
     */
    public function mergeDuplicates(): int
    {
        $st = $this->app->store; $files = $this->app->files(); $n = 0;
        $groups = $st->q('SELECT folder, name COLLATE NOCASE AS n FROM files WHERE deleted_at IS NULL GROUP BY folder, name COLLATE NOCASE HAVING COUNT(*) > 1')->fetchAll();
        foreach ($groups as $g) {
            if (self::readonly((string)$g['folder'])) continue;
            $rows = $st->q('SELECT * FROM files WHERE folder = ? AND name = ? COLLATE NOCASE AND deleted_at IS NULL ORDER BY created DESC, id DESC', [$g['folder'], $g['n']])->fetchAll();
            $keep = array_shift($rows);
            foreach (array_reverse($rows) as $old) { // oldest first, so the newest version is the last one kept
                $files->keepVersion($keep, $old);
                $this->absorb((int)$keep['id'], $old);
                $n++;
            }
        }
        if ($n) Log::info("library: $n files with a name already used in their folder became versions");
        return $n;
    }

    /** Text files the assistant writes: the extension decides the format; Markdown (or plain text) is a note, editable in the app. */
    public const TEXT_TYPES = ['md' => 'text/markdown', 'markdown' => 'text/markdown', 'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
        'csv' => 'text/csv', 'json' => 'application/json', 'xml' => 'application/xml'];

    public static function isNoteName(string $name): bool { return (bool)preg_match('/\.(md|markdown|txt)$/i', $name); }

    public static function textMime(string $name): string { return self::TEXT_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'text/plain'; }

    /** A file name as shown in the library: anything but slashes, control characters and the few that file systems refuse. */
    public static function cleanName(string $name): string
    {
        $name = preg_replace('#.*[/\\\\]#s', '', $name);
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f<>:"|?*]+/u', '_', $name)), 0, 120);
    }

    /** Delete a file: to the trash, restorable for TRASH_DAYS days. */
    public function deleteFile(int $id): void { $this->trashFile($id); }

    private function trashFile(int $id, string $with = '', ?int $at = null): void
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        self::guard((string)$f['folder']);
        $this->app->store->q('UPDATE files SET deleted_at = ?, deleted_with = ? WHERE id = ?', [$at ?? time(), $with, $id]);
        $this->app->store->deleteDocs(['file', 'transcript'], $id); // out of searches; its text stays, for a restore
    }

    /** Delete for good: the file on disk, its index and previews. */
    public function purgeFile(int $id): void
    {
        $f = $this->app->files()->get($id, true) ?? throw new \InvalidArgumentException("no file $id");
        self::guard((string)$f['folder']);
        $this->app->store->deleteDocs(['file', 'transcript'], $id);
        $this->app->store->q('DELETE FROM files WHERE id = ?', [$id]);
        if ($f['path'] !== '' && is_file($f['path'])) @unlink($f['path']);
        $this->app->files()->dropPreviews($id);
        $this->app->files()->purgeVersions($id);
    }

    /**
     * What is in the trash, newest first: files deleted on their own, and folders deleted with their files
     * (one entry per folder deletion, restored together).
     */
    public function trash(): array
    {
        $rows = $this->app->store->q('SELECT id, name, folder, kind, size, deleted_at, deleted_with FROM files WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, folder, name COLLATE NOCASE')->fetchAll();
        $out = []; $groups = [];
        foreach ($rows as $r) {
            $at = (int)$r['deleted_at']; $until = $at + self::TRASH_DAYS * 86400;
            if ((string)$r['deleted_with'] === '') {
                $out[] = ['type' => 'file', 'id' => (int)$r['id'], 'name' => $r['name'], 'folder' => $r['folder'], 'kind' => $r['kind'], 'size' => (int)$r['size'], 'deleted_at' => $at, 'until' => $until];
                continue;
            }
            $key = $r['deleted_with'] . "\0$at";
            if (!isset($groups[$key])) { $groups[$key] = count($out); $out[] = ['type' => 'folder', 'path' => $r['deleted_with'], 'files' => 0, 'size' => 0, 'deleted_at' => $at, 'until' => $until, 'names' => []]; }
            $g = &$out[$groups[$key]];
            $g['files']++; $g['size'] += (int)$r['size'];
            if (count($g['names']) < 5) $g['names'][] = $r['name'];
            unset($g);
        }
        return $out;
    }

    /** Back from the trash, to its folder (created again if needed). A file id, or a folder deletion (its path and time). */
    public function restore(?int $id = null, string $folder = '', int $at = 0): array
    {
        if ($id) $ids = [$id];
        else $ids = array_map('intval', array_column($this->app->store->q('SELECT id FROM files WHERE deleted_with = ? AND deleted_at = ?', [self::normalize($folder), $at])->fetchAll(), 'id'));
        $done = [];
        foreach ($ids as $i) {
            $f = $this->app->files()->get($i, true);
            if (!$f || $f['deleted_at'] === null) continue;
            self::guard((string)$f['folder']);
            $this->ensureFolder((string)$f['folder']);
            $name = $this->freeName((string)$f['folder'], (string)$f['name'], $i); // its name taken since: keep both
            $this->app->store->q('UPDATE files SET deleted_at = NULL, deleted_with = NULL, name = ? WHERE id = ?', [$name, $i]);
            if ($name !== $f['name']) $this->relabel(['name' => $name] + $f);
            if ($f['status'] === 'ready') $this->app->files()->index($i); // searchable again, from the text kept (nothing read again)
            $done[] = $i;
        }
        if (!$done) throw new \InvalidArgumentException('Nothing to restore: it is no longer in the trash.');
        return ['restored' => count($done), 'folder' => $this->app->files()->get($done[0])['folder'] ?? '/'];
    }

    /** Empty the trash (or only what has been there for $days days). Returns the number of files deleted for good. */
    public function purgeTrash(?int $days = self::TRASH_DAYS): int
    {
        $ids = $this->app->store->q('SELECT id FROM files WHERE deleted_at IS NOT NULL AND deleted_at <= ?', [$days === null ? PHP_INT_MAX : time() - $days * 86400])->fetchAll();
        foreach ($ids as $r) $this->purgeFile((int)$r['id']);
        if ($ids) Log::info('library trash: ' . count($ids) . ' files deleted for good');
        $v = $this->app->files()->purgeVersions(null, $days === null ? PHP_INT_MAX : time() - $days * 86400);
        if ($v) Log::info("library: $v earlier versions deleted for good");
        return count($ids);
    }

    /** Create or update a note: Markdown by default, or the text format its name says (page.html, data.csv…). */
    public function saveNote(string $folder, string $name, string $content, ?int $id = null): array
    {
        $files = $this->app->files();
        if ($id !== null) {
            $f = $files->get($id) ?? throw new \InvalidArgumentException("no file $id");
            self::guard((string)$f['folder']);
            if (!$f['note'] && $f['kind'] !== 'text') throw new \InvalidArgumentException('only notes and text files can be edited');
            $tmp = tempnam(sys_get_temp_dir(), 'note');
            file_put_contents($tmp, $content);
            try { $files->replace($id, $tmp, (string)$f['mime'], true); } // the earlier text kept as a version
            finally { if (is_file($tmp)) @unlink($tmp); }
            if ($name !== '' && $name !== $f['name']) $id = (int)$this->renameFile($id, $name)['id'];
        } else {
            $name = trim($name) !== '' ? trim($name) : 'Note ' . date('Y-m-d H.i');
            if (!isset(self::TEXT_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) $name .= '.md';
            $tmp = tempnam(sys_get_temp_dir(), 'note');
            file_put_contents($tmp, $content);
            $id = $files->save($tmp, $name, self::textMime($name), $folder, true); // same name: a new version of that file
            if (self::isNoteName($name)) $this->app->store->q('UPDATE files SET note = 1 WHERE id = ?', [$id]);
        }
        $files->text($id); // small: index right away
        return $files->get($id);
    }

    /** Search the library (optionally one folder and its subfolders). $meaning false: keywords only (instant). */
    public function search(string $query, string $folder = '/', int $limit = 10, bool $meaning = true): array
    {
        $folder = self::normalize($folder);
        $hits = $this->app->recall()->search($query, $limit, PHP_INT_MAX, ['file', 'transcript'], $folder === '/' ? null : $this->fileIds($folder), null, $meaning);
        $out = [];
        foreach ($hits as $h) {
            $f = $this->app->files()->get((int)$h['ref']);
            if (!$f) continue;
            $out[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'folder' => $f['folder'], 'kind' => $f['kind'], 'snippet' => preg_replace('/\s+/', ' ', mb_substr($h['text'], 0, 400)),
                'page' => preg_match('/ p\. (\d+)(?:-(\d+))?$/', (string)$h['label'], $pm) ? self::bestPage($f, $query, (int)$pm[1], (int)($pm[2] ?? $pm[1])) : null,
                'score' => round($h['score'], 4), 'sim' => $h['sim'] !== null ? round($h['sim'], 3) : null];
        }
        return $out;
    }

    /** "Where is…?": files and folders whose name holds every word of $q (case and accents ignored), the closest matches first. */
    public function find(string $q, int $limit = 20, string $folder = '/'): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', self::fold($q)), fn($w) => $w !== ''));
        if (!$words) return ['files' => [], 'folders' => []];
        $folder = self::normalize($folder);
        $match = function (string $name) use ($words): ?int {
            $n = self::fold($name);
            foreach ($words as $w) if (!str_contains($n, $w)) return null;
            return (str_starts_with($n, $words[0]) ? 0 : 1) * 1000 + mb_strlen($n); // starts with it, then the shortest
        };
        $folders = [];
        foreach ($this->tree() as $t) {
            if ($folder !== '/' && !str_starts_with($t['path'], "$folder/")) continue;
            if (($r = $match($t['name'])) !== null) $folders[] = ['path' => $t['path'], 'total' => $t['total'], 'r' => $r];
        }
        [$w, $a] = self::within($folder);
        $files = [];
        foreach ($this->app->store->q("SELECT id, name, folder, kind FROM files WHERE deleted_at IS NULL AND $w", $a) as $f)
            if (($r = $match((string)$f['name'])) !== null) $files[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'folder' => $f['folder'], 'kind' => $f['kind'], 'r' => $r];
        $sort = function (array $l) use ($limit) { usort($l, fn($x, $y) => $x['r'] <=> $y['r']); return array_map(function ($x) { unset($x['r']); return $x; }, array_slice($l, 0, $limit)); };
        return ['files' => $sort($files), 'folders' => $sort($folders)];
    }

    /** Lower case without accents, for comparing names. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) return preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
        return strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ÿ'=>'y','ñ'=>'n','œ'=>'oe','æ'=>'ae']);
    }

    /** In a passage spanning several pages, the page where the searched words are. */
    private static function bestPage(array $f, string $query, int $from, int $to): int
    {
        if ($from === $to) return $from;
        $pages = Files::pages((string)$f['text']) ?? [];
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $m);
        $best = $from; $bestScore = -1;
        for ($p = $from; $p <= $to; $p++) {
            $text = mb_strtolower($pages[$p - 1] ?? '');
            $score = 0;
            foreach (array_unique($m[0]) as $w) if (str_contains($text, $w)) $score++;
            if ($score > $bestScore) { $best = $p; $bestScore = $score; }
        }
        return $best;
    }

    /** @return int[] */
    public function fileIds(string $folder): array
    {
        [$w, $a] = self::within(self::normalize($folder));
        return array_map('intval', array_column($this->app->store->q("SELECT id FROM files WHERE $w AND deleted_at IS NULL", $a)->fetchAll(), 'id'));
    }

    public function stats(): array
    {
        $r = $this->app->store->q("SELECT COUNT(*) n, COALESCE(SUM(size),0) bytes, SUM(status='pending') pending, SUM(status='failed') failed FROM files WHERE deleted_at IS NULL")->fetch();
        $t = $this->app->store->q('SELECT COUNT(*) n, COALESCE(SUM(size),0) bytes FROM files WHERE deleted_at IS NOT NULL')->fetch();
        return ['files' => (int)$r['n'], 'bytes' => (int)$r['bytes'], 'pending' => (int)$r['pending'], 'failed' => (int)$r['failed'],
            'trash' => (int)$t['n'], 'trash_bytes' => (int)$t['bytes']];
    }

    private function relabel(array $f): void
    {
        $this->app->store->q("UPDATE docs SET label = ? WHERE kind IN ('file','transcript') AND ref = ?", [self::label($f), $f['id']]);
    }
}
