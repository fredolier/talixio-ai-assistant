<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The library as a network drive (WebDAV, RFC 4918) at /dav/: Finder, Windows Explorer, Files on
 * Android/iOS, Cyberduck, rclone... Folders and files are the library's own: a file put here is read
 * and indexed like an upload, a file deleted goes to the library's trash.
 *
 * Off by default. Its own password (not the app's: a drive keeps it in the system's keychain, without
 * two-step sign-in), shown once and kept as a hash; read only or read and write. Folders of secret
 * spaces never show. System clutter (.DS_Store, ._ files, Thumbs.db...) is kept aside, out of the library.
 */
final class Dav
{
    public const MODES = ['off', 'read', 'write'];
    public const USER = 'talixio';
    private const MAX_FAILS = 10; // wrong passwords per address within Auth::WINDOW

    public function __construct(private App $app) {}

    // ---- settings ----

    public function mode(): string
    {
        $m = $this->app->store->get('dav_mode', 'off');
        return in_array($m, self::MODES, true) && $this->app->store->get('dav_hash') !== '' ? $m : 'off';
    }

    public function status(): array
    {
        $base = $this->app->shares()->baseUrl();
        return ['mode' => $this->mode(), 'password' => $this->app->store->get('dav_hash') !== '', 'user' => self::USER,
            'url' => ($base !== '' ? $base : Config::basePath()) . '/dav/', 'since' => (int)$this->app->store->get('dav_since', '0')];
    }

    public function setMode(string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) throw new \InvalidArgumentException('unknown mode');
        if ($mode !== 'off' && $this->app->store->get('dav_hash') === '') throw new \InvalidArgumentException('Create a password for the drive first.');
        $this->app->store->set('dav_mode', $mode);
        Log::info("dav: $mode");
        return $this->status();
    }

    /** A new password (the previous one stops working). Long and random: a fast hash is enough to keep it. */
    public function newPassword(): string
    {
        $pw = implode('-', str_split(Auth::base32(random_bytes(15)), 6));
        $this->app->store->set('dav_hash', hash('sha256', $pw));
        $this->app->store->set('dav_since', (string)time());
        if (!in_array($this->app->store->get('dav_mode', 'off'), ['read', 'write'], true)) $this->app->store->set('dav_mode', 'read');
        return $pw;
    }

    // ---- requests ----

    public function handle(string $method, string $rawPath): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('DAV: 1, 2');
        header('MS-Author-Via: DAV');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache');
        $method = strtoupper($method);
        if ($this->mode() === 'off') { $this->status404(); return; }
        if (!$this->signedIn()) return;
        $path = self::decode($rawPath);
        try {
            match ($method) {
                'OPTIONS' => $this->options(),
                'PROPFIND' => $this->propfind($path),
                'GET', 'HEAD' => $this->get($path),
                'PUT' => $this->put($path),
                'MKCOL' => $this->mkcol($path),
                'DELETE' => $this->delete($path),
                'MOVE', 'COPY' => $this->moveOrCopy($path, $method === 'COPY'),
                'LOCK' => $this->lock($path),
                'UNLOCK' => http_response_code(204),
                'PROPPATCH' => $this->proppatch($path),
                default => $this->send(405, ''),
            };
        } catch (\InvalidArgumentException $e) {
            $this->send(403, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("dav $method $path: {$e->getMessage()}");
            $this->send(500, 'error');
        }
    }

    /** Basic auth with the drive's password; wrong ones are counted per address. */
    private function signedIn(): bool
    {
        $auth = new Auth($this->app);
        $ip = Auth::clientIp();
        if ($auth->count("dav:$ip", Auth::WINDOW) >= self::MAX_FAILS) { header('Retry-After: ' . Auth::WINDOW); $this->send(429, 'Too many wrong passwords: try again later.'); return false; }
        $pw = null;
        if (preg_match('/^Basic\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
            $pair = (string)base64_decode($m[1], true);
            $pw = str_contains($pair, ':') ? substr($pair, strpos($pair, ':') + 1) : null;
        } elseif (isset($_SERVER['PHP_AUTH_PW'])) {
            $pw = (string)$_SERVER['PHP_AUTH_PW'];
        }
        if ($pw !== null && hash_equals($this->app->store->get('dav_hash'), hash('sha256', strtoupper(trim($pw))))) return true;
        if ($pw !== null) {
            $auth->hit("dav:$ip");
            if ($auth->count("dav:$ip", Auth::WINDOW) === self::MAX_FAILS) Log::warn("dav: $ip blocked after " . self::MAX_FAILS . ' wrong passwords');
        }
        header('WWW-Authenticate: Basic realm="' . addcslashes($this->app->cfg->name, '"\\') . ' (library)", charset="UTF-8"');
        $this->send(401, 'Sign in with the drive password (Settings → Network drive).');
        return false;
    }

    private function writable(): void
    {
        if ($this->mode() !== 'write') throw new \InvalidArgumentException('The drive is read only (Settings → Network drive).');
    }

    private function options(): void
    {
        header('Allow: OPTIONS, PROPFIND, GET, HEAD' . ($this->mode() === 'write' ? ', PUT, MKCOL, DELETE, MOVE, COPY, LOCK, UNLOCK, PROPPATCH' : ', LOCK, UNLOCK, PROPPATCH'));
        header('Content-Length: 0');
    }

    // ---- what a path is ----

    /** '/A/B/name' from the request path after /dav (percent-encoded). */
    public static function decode(string $raw): string
    {
        $parts = [];
        foreach (explode('/', $raw) as $p) {
            $p = rawurldecode($p);
            if ($p === '' || $p === '.') continue;
            if ($p === '..') { array_pop($parts); continue; }
            $parts[] = $p;
        }
        return '/' . implode('/', $parts);
    }

    /** The address of a path, as clients see it. */
    private static function href(string $path, bool $folder): string
    {
        $enc = implode('/', array_map('rawurlencode', array_filter(explode('/', $path), 'strlen')));
        return Config::basePath() . '/dav/' . $enc . ($folder && $enc !== '' ? '/' : '');
    }

    /** Clutter the systems write next to files: kept aside, never in the library. */
    public static function junk(string $name): bool
    {
        return (bool)preg_match('/^(\.DS_Store|\._.*|Thumbs\.db|desktop\.ini|\.localized|\.TemporaryItems|\.Trashes|\.Spotlight-V100|\.fseventsd|\.hidden|~\$.*|\.~lock\..*#)$/i', $name);
    }

    private function junkPath(string $path): string
    {
        $dir = $this->app->cfg->dataDir . '/dav-junk';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir . '/' . sha1($path);
    }

    /** Folders of secret spaces stay out of the drive. */
    private function hidden(string $folder): bool
    {
        return $this->app->privacy()->folderLevel(Library::normalize($folder)) === 'secret';
    }

    /**
     * What is at a path: ['folder', path], ['file', row] or null. Two files with the same name in a
     * folder show as "name (#id).ext" for all but the newest.
     */
    private function resolve(string $path): ?array
    {
        if ($path === '/') return ['folder', '/'];
        $folder = Library::normalize(dirname($path)); $name = basename($path);
        if ($this->hidden($path) || $this->hidden($folder)) return null;
        $st = $this->app->store;
        $f = $st->q('SELECT * FROM files WHERE folder = ? AND name = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$folder, $name])->fetch();
        if (!$f && preg_match('/ \(#(\d+)\)(\.[^.]*)?$/', $name, $m)) {
            $f = $st->q('SELECT * FROM files WHERE id = ? AND folder = ? AND deleted_at IS NULL', [(int)$m[1], $folder])->fetch();
            if ($f && $this->shownName($f) !== $name) $f = false;
        }
        if ($f) return ['file', $f];
        $norm = Library::normalize($path);
        if ($norm === $path && $this->app->library()->exists($path)) return ['folder', $path];
        foreach ($this->app->library()->tree() as $t) if ($t['path'] === $norm) return ['folder', $norm]; // default folders
        return null;
    }

    private function shownName(array $f): string
    {
        $newest = (int)$this->app->store->q('SELECT MAX(id) FROM files WHERE folder = ? AND name = ? AND deleted_at IS NULL', [$f['folder'], $f['name']])->fetchColumn();
        return self::numbered((string)$f['name'], (int)$f['id'], $newest);
    }

    private static function numbered(string $name, int $id, int $newest): string
    {
        if ($id === $newest) return $name;
        return preg_match('/^(.+)(\.[^.]+)$/', $name, $m) ? "$m[1] (#$id)$m[2]" : "$name (#$id)";
    }

    // ---- reading ----

    private function propfind(string $path): void
    {
        $depth = (string)($_SERVER['HTTP_DEPTH'] ?? 'infinity');
        $name = basename($path);
        if ($path !== '/' && self::junk($name)) {
            $j = $this->junkPath($path);
            if (!is_file($j)) { $this->status404(); return; }
            $this->multistatus([$this->entry(self::href($path, false), $name, false, filesize($j), 'application/octet-stream', filemtime($j))]);
            return;
        }
        $r = $this->resolve($path) ?? null;
        if (!$r) { $this->status404(); return; }
        if ($r[0] === 'file') { $this->multistatus([$this->fileEntry($r[1], $path)]); return; }
        $out = [$this->folderEntry($r[1])];
        if ($depth !== '0') {
            $list = $this->app->library()->list($r[1]);
            foreach ($list['folders'] as $sub) if (!$this->hidden($sub['path'])) $out[] = $this->folderEntry($sub['path']);
            $newest = [];
            foreach ($list['files'] as $f) $newest[$f['name']] = max($newest[$f['name']] ?? 0, (int)$f['id']);
            foreach ($list['files'] as $f) {
                $row = $this->app->files()->get((int)$f['id']);
                if ($row) $out[] = $this->fileEntry($row, Library::join($r[1], self::numbered((string)$f['name'], (int)$f['id'], $newest[$f['name']])));
            }
        }
        $this->multistatus($out);
    }

    private function folderEntry(string $path): string
    {
        $created = (int)$this->app->store->q('SELECT created FROM folders WHERE path = ?', [$path])->fetchColumn();
        return $this->entry(self::href($path, true), $path === '/' ? $this->app->cfg->name : basename($path), true, null, null, $created ?: time());
    }

    private function fileEntry(array $f, string $path): string
    {
        $mtime = is_file($f['path']) ? (int)filemtime($f['path']) : (int)$f['created'];
        return $this->entry(self::href($path, false), basename($path), false, is_file($f['path']) ? (int)filesize($f['path']) : (int)$f['size'], (string)$f['mime'], $mtime, (int)$f['created'], (int)$f['id']);
    }

    private function entry(string $href, string $name, bool $folder, ?int $size, ?string $mime, int $mtime, ?int $created = null, ?int $id = null): string
    {
        $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $p = '<d:displayname>' . $x($name) . '</d:displayname>'
            . '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT</d:getlastmodified>'
            . '<d:creationdate>' . gmdate('Y-m-d\TH:i:s\Z', $created ?? $mtime) . '</d:creationdate>';
        if ($folder) {
            $p .= '<d:resourcetype><d:collection/></d:resourcetype>';
        } else {
            $p .= '<d:resourcetype/><d:getcontentlength>' . (int)$size . '</d:getcontentlength><d:getcontenttype>' . $x((string)$mime) . '</d:getcontenttype>'
                . '<d:getetag>"' . ($id ?? 0) . '-' . (int)$size . '-' . $mtime . '"</d:getetag>';
        }
        $p .= '<d:supportedlock><d:lockentry><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype></d:lockentry></d:supportedlock>';
        return '<d:response><d:href>' . $x($href) . '</d:href><d:propstat><d:prop>' . $p . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
    }

    private function multistatus(array $responses): void
    {
        $this->send(207, '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<d:multistatus xmlns:d="DAV:">' . implode('', $responses) . '</d:multistatus>', 'application/xml; charset=utf-8');
    }

    private function get(string $path): void
    {
        if (self::junk(basename($path))) {
            $j = $this->junkPath($path);
            if (!is_file($j)) { $this->status404(); return; }
            Web::sendPath($j, 'application/octet-stream', 'attachment', "default-src 'none'; sandbox");
            return;
        }
        $r = $this->resolve($path);
        if (!$r) { $this->status404(); return; }
        if ($r[0] === 'folder') { $this->folderPage($r[1]); return; }
        $f = $r[1];
        if (!is_file($f['path'])) { $this->status404(); return; }
        Web::sendPath($f['path'], (string)$f['mime'], 'attachment; filename*=UTF-8\'\'' . rawurlencode((string)$f['name']), "default-src 'none'; sandbox");
    }

    /** A folder opened in a browser: a plain list of links. */
    private function folderPage(string $folder): void
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $list = $this->app->library()->list($folder);
        $rows = $folder === '/' ? '' : '<li><a href="' . $e(self::href(Library::parent($folder), true)) . '">..</a></li>';
        foreach ($list['folders'] as $s) if (!$this->hidden($s['path'])) $rows .= '<li><a href="' . $e(self::href($s['path'], true)) . '">' . $e($s['name']) . '/</a></li>';
        foreach ($list['files'] as $f) $rows .= '<li><a href="' . $e(self::href(Library::join($folder, (string)$f['name']), false)) . '">' . $e((string)$f['name']) . '</a></li>';
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        $this->send(200, '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($folder) . '</title>'
            . '<style>body{font:15px system-ui,sans-serif;margin:16px}li{margin:4px 0}</style><h1>' . $e($folder) . '</h1><ul>' . $rows . '</ul>', 'text/html; charset=utf-8');
    }

    // ---- writing ----

    /** The request body in a temporary file. */
    private static function body(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dav');
        $in = fopen('php://input', 'rb'); $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
        return $tmp;
    }

    private function put(string $path): void
    {
        $this->writable();
        $name = basename($path); $folder = Library::normalize(dirname($path));
        if ($path === '/' || $name === '') { $this->send(405, ''); return; }
        if ($this->hidden($folder)) { $this->status404(); return; }
        if (self::junk($name)) {
            $j = $this->junkPath($path); $new = !is_file($j);
            $tmp = self::body(); rename($tmp, $j);
            $this->send($new ? 201 : 204, '');
            return;
        }
        $r = $this->resolve($path);
        if ($r && $r[0] === 'folder') { $this->send(405, 'a folder has this name'); return; }
        if (!$r && !$this->app->library()->exists($folder)) { $this->send(409, 'no such folder'); return; }
        Library::guard($folder);
        $tmp = self::body();
        try {
            if ($r) { $this->app->files()->replace((int)$r[1]['id'], $tmp); $this->send(204, ''); return; }
            $clean = Library::cleanName($name);
            if ($clean === '' || $clean === '.' || $clean === '..') throw new \InvalidArgumentException('invalid name');
            $id = $this->app->files()->save($tmp, $clean, '', $folder);
            if ($this->app->files()->get($id)['name'] !== $clean) $this->app->library()->renameFile($id, $clean); // save() keeps a stricter name for the disk
            $this->send(201, '');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function mkcol(string $path): void
    {
        $this->writable();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { $this->send(415, ''); return; }
        if ($path === '/' || $this->resolve($path)) { $this->send(405, 'already exists'); return; }
        $parent = Library::normalize(dirname($path));
        if ($this->hidden($path) || $this->hidden($parent)) { $this->status404(); return; }
        if (!$this->app->library()->exists($parent) && !$this->resolve($parent)) { $this->send(409, 'no such folder'); return; }
        if (Library::normalize($path) !== $path) throw new \InvalidArgumentException('invalid folder name');
        $this->app->library()->createFolder($path);
        $this->send(201, '');
    }

    private function delete(string $path): void
    {
        $this->writable();
        if (self::junk(basename($path))) { @unlink($this->junkPath($path)); $this->send(204, ''); return; }
        $r = $this->resolve($path);
        if (!$r) { $this->status404(); return; }
        if ($r[0] === 'folder') {
            if ($r[1] === '/') throw new \InvalidArgumentException('the root folder cannot be deleted');
            $this->app->library()->deleteFolder($r[1], true); // to the trash, with its files
        } else {
            $this->app->library()->deleteFile((int)$r[1]['id']);
        }
        $this->send(204, '');
    }

    /** MOVE renames or moves; COPY makes new files. Folders move with everything in them. */
    private function moveOrCopy(string $path, bool $copy): void
    {
        $this->writable();
        $dest = (string)($_SERVER['HTTP_DESTINATION'] ?? '');
        $dpath = (string)parse_url($dest, PHP_URL_PATH);
        $prefix = Config::basePath() . '/dav';
        if ($dpath !== $prefix && !str_starts_with($dpath, "$prefix/")) { $this->send(502, 'the destination is not on this drive'); return; }
        $to = self::decode(substr($dpath, strlen($prefix)));
        $overwrite = strtoupper(trim((string)($_SERVER['HTTP_OVERWRITE'] ?? 'T'))) !== 'F';
        if ($to === $path) { $this->send(403, 'same place'); return; }
        $toFolder = Library::normalize(dirname($to)); $toName = basename($to);
        if ($to === '/' || $this->hidden($to) || $this->hidden($toFolder)) { $this->send(403, 'not there'); return; }
        if (self::junk(basename($path))) { // clutter stays clutter
            $j = $this->junkPath($path);
            if (!is_file($j)) { $this->status404(); return; }
            if (self::junk($toName)) { $copy ? copy($j, $this->junkPath($to)) : rename($j, $this->junkPath($to)); }
            elseif (!$copy) @unlink($j);
            $this->send(201, '');
            return;
        }
        $src = $this->resolve($path);
        if (!$src) { $this->status404(); return; }
        if ($src[0] === 'folder' && ($src[1] === '/' || str_starts_with($to . '/', rtrim($src[1], '/') . '/'))) { $this->send(403, 'a folder cannot go inside itself'); return; }
        $existing = $this->resolve($to);
        if ($existing && !$overwrite) { $this->send(412, 'already exists'); return; }
        if (!$this->app->library()->exists($toFolder) && !$this->resolve($toFolder)) { $this->send(409, 'no such folder'); return; }
        $lib = $this->app->library();
        Library::guard($toFolder, $to);
        if (!$copy) Library::guard($src[0] === 'file' ? (string)$src[1]['folder'] : $src[1]);
        if ($existing) {
            if ($existing[0] === 'file' && $src[0] === 'file' && (int)$existing[1]['id'] === (int)$src[1]['id']) $existing = null; // same file: a change of case, say
            elseif ($existing[0] === 'file') $lib->deleteFile((int)$existing[1]['id']);
            else $lib->deleteFolder($existing[1], true);
        }
        if ($src[0] === 'file') {
            $id = (int)$src[1]['id'];
            if ($copy) {
                $tmp = tempnam(sys_get_temp_dir(), 'dav'); copy($src[1]['path'], $tmp);
                $id = $this->app->files()->save($tmp, Library::cleanName($toName), (string)$src[1]['mime'], $toFolder);
                $lib->renameFile($id, $toName);
            } else {
                if ((string)$src[1]['folder'] !== $toFolder) $lib->moveFile($id, $toFolder);
                if ((string)$src[1]['name'] !== $toName) $lib->renameFile($id, $toName);
            }
        } else {
            $target = Library::normalize($to);
            if ($copy) $this->copyFolder($src[1], $target);
            else $lib->moveFolder($src[1], $target);
        }
        $this->send($existing ? 204 : 201, '');
    }

    private function copyFolder(string $from, string $to): void
    {
        $lib = $this->app->library();
        $lib->createFolder($to);
        foreach ($lib->tree() as $t) if (str_starts_with($t['path'], "$from/")) $lib->createFolder($to . substr($t['path'], strlen($from)));
        [$w, $a] = Library::within($from);
        foreach ($this->app->store->q("SELECT * FROM files WHERE $w AND deleted_at IS NULL", $a)->fetchAll() as $f) {
            if (!is_file($f['path'])) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'dav'); copy($f['path'], $tmp);
            $id = $this->app->files()->save($tmp, (string)$f['name'], (string)$f['mime'], $to . substr((string)$f['folder'], strlen($from)));
            if ($f['note']) $this->app->store->q('UPDATE files SET note = 1 WHERE id = ?', [$id]);
            $lib->renameFile($id, (string)$f['name']);
        }
    }

    /**
     * Locks are only acknowledged (one person, one library): Windows and Office need them to save.
     * A lock on a name not in use reserves it with an empty file, as the standard says.
     */
    private function lock(string $path): void
    {
        $token = 'opaquelocktoken:' . bin2hex(random_bytes(16));
        $created = false;
        if ($this->mode() === 'write' && $path !== '/' && !self::junk(basename($path)) && !$this->resolve($path)) {
            $folder = Library::normalize(dirname($path));
            if (!$this->hidden($folder) && !Library::readonly($folder) && $this->app->library()->exists($folder)) {
                $tmp = tempnam(sys_get_temp_dir(), 'dav');
                $id = $this->app->files()->save($tmp, Library::cleanName(basename($path)), '', $folder);
                $this->app->library()->renameFile($id, basename($path));
                $created = true;
            }
        }
        $timeout = 'Second-3600';
        header('Lock-Token: <' . $token . '>');
        $this->send($created ? 201 : 200, '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<d:prop xmlns:d="DAV:"><d:lockdiscovery><d:activelock>'
            . '<d:locktype><d:write/></d:locktype><d:lockscope><d:exclusive/></d:lockscope><d:depth>' . (($_SERVER['HTTP_DEPTH'] ?? '') === '0' ? '0' : 'infinity') . '</d:depth>'
            . '<d:owner>' . self::USER . '</d:owner><d:timeout>' . $timeout . '</d:timeout><d:locktoken><d:href>' . $token . '</d:href></d:locktoken>'
            . '<d:lockroot><d:href>' . htmlspecialchars(self::href($path, false), ENT_XML1) . '</d:href></d:lockroot></d:activelock></d:lockdiscovery></d:prop>', 'application/xml; charset=utf-8');
    }

    /** Properties clients set (Windows' file times...) are accepted and not kept. */
    private function proppatch(string $path): void
    {
        if ($path !== '/' && !self::junk(basename($path)) && !$this->resolve($path)) { $this->status404(); return; }
        $props = '';
        $xml = (string)file_get_contents('php://input');
        $doc = new \DOMDocument();
        if ($xml !== '' && @$doc->loadXML($xml, LIBXML_NONET)) {
            foreach (['set', 'remove'] as $op) foreach ($doc->getElementsByTagNameNS('DAV:', $op) as $el) foreach ($el->getElementsByTagNameNS('DAV:', 'prop') as $prop)
                foreach ($prop->childNodes as $n) if ($n instanceof \DOMElement) $props .= '<x:' . $n->localName . ' xmlns:x="' . htmlspecialchars((string)$n->namespaceURI, ENT_XML1 | ENT_QUOTES) . '"/>';
        }
        $this->multistatus(['<d:response><d:href>' . htmlspecialchars(self::href($path, false), ENT_XML1) . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>']);
    }

    // ---- replies ----

    private function status404(): void { $this->send(404, 'Not found'); }

    private function send(int $code, string $body, string $type = 'text/plain; charset=utf-8'): void
    {
        http_response_code($code);
        if ($body !== '') header('Content-Type: ' . $type);
        header('Content-Length: ' . strlen($body));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $body;
    }
}
