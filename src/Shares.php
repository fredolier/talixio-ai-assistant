<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Links that let someone outside open documents of the library: one file, or a folder with its subfolders.
 * Each link has a long random address, an end date (or none), an optional password, "view only" or "view and
 * download", a note on who it is for, and an optional message shown to them. Every opening is logged; you
 * can be told the first time it is opened. A revoked or expired link shows "no longer available".
 * Public pages: Web::sharePage (/s/<token>); management: Library page and Settings → Shares.
 */
final class Shares
{
    public const EXPIRY_DAYS = [1, 7, 30, 90, 0]; // 0 = no end date
    private const MAX_TRIES = 10;                 // wrong passwords per link and address, per 15 minutes

    public function __construct(private App $app) {}

    /**
     * $what: ['file' => id] or ['folder' => '/path'].
     * $o: label (who it is for), message, days (0 = never), password, download (bool), notify (bool), by ('you'|'assistant').
     */
    public function create(array $what, array $o = []): array
    {
        $st = $this->app->store;
        $fileId = null; $folder = null;
        if (isset($what['file'])) {
            $f = $this->app->files()->get((int)$what['file']) ?? throw new \InvalidArgumentException('No such file.');
            $fileId = (int)$f['id'];
        } else {
            $folder = Library::normalize((string)($what['folder'] ?? ''));
            if ($folder === '/') throw new \InvalidArgumentException('The whole library cannot be shared: choose a folder.');
            if (!$this->app->library()->exists($folder)) throw new \InvalidArgumentException("No folder $folder.");
        }
        $days = (int)($o['days'] ?? 30);
        if ($days < 0 || $days > 3650) throw new \InvalidArgumentException('days: 0 (no end) to 3650');
        $pw = (string)($o['password'] ?? '');
        if ($pw !== '' && mb_strlen($pw) < 4) throw new \InvalidArgumentException('The password needs at least 4 characters.');
        $level = $this->app->privacy()->folderLevel($folder ?? (string)$f['folder']);
        if ($folder !== null && $this->secretInside($folder)) throw new \InvalidArgumentException('A Secret space is inside this folder: it cannot be shared outside.');
        if ($level === 'secret') throw new \InvalidArgumentException('This belongs to a Secret space: it cannot be shared outside.');
        if ($level === 'confidential') {
            if ($pw === '') throw new \InvalidArgumentException('This belongs to a Confidential space: a password is required.');
            if ($days === 0 || $days > 30) throw new \InvalidArgumentException('This belongs to a Confidential space: 30 days at most.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $st->q('INSERT INTO shares(token, file_id, folder, label, message, created, expires, password, download, notify, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [
            $token, $fileId, $folder, mb_substr(trim((string)($o['label'] ?? '')), 0, 120), mb_substr(trim((string)($o['message'] ?? '')), 0, 2000),
            time(), $days ? time() + $days * 86400 : null, $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null,
            ($o['download'] ?? true) ? 1 : 0, ($o['notify'] ?? true) ? 1 : 0, ($o['by'] ?? 'you') === 'assistant' ? 'assistant' : 'you']);
        return $this->get((int)$st->db->lastInsertId());
    }

    public function get(int $id): ?array
    {
        $s = $this->app->store->q('SELECT * FROM shares WHERE id = ?', [$id])->fetch();
        return $s ? $this->info($s) : null;
    }

    /** A link as the app shows it (never the password hash). */
    private function info(array $s): array
    {
        $f = $s['file_id'] ? $this->app->files()->get((int)$s['file_id']) : null;
        $now = time();
        $state = $s['revoked'] ? 'revoked' : ($s['expires'] && (int)$s['expires'] < $now ? 'expired' : ($s['file_id'] && !$f ? 'missing' : 'active'));
        return ['id' => (int)$s['id'], 'url' => $this->url($s['token']), 'file_id' => $s['file_id'] ? (int)$s['file_id'] : null, 'folder' => $s['folder'],
            'name' => $f ? $f['name'] : ($s['folder'] !== null ? basename((string)$s['folder']) : '?'), 'path' => $f ? Library::join($f['folder'], $f['name']) : $s['folder'],
            'label' => $s['label'], 'message' => $s['message'], 'created' => (int)$s['created'], 'expires' => $s['expires'] ? (int)$s['expires'] : null,
            'password' => $s['password'] !== null, 'download' => (bool)$s['download'], 'notify' => (bool)$s['notify'], 'created_by' => $s['created_by'],
            'views' => (int)$s['views'], 'last_access' => $s['last_access'] ? (int)$s['last_access'] : null, 'state' => $state];
    }

    /** Links, newest first. $scope: active | all; optionally only those of one file or folder. */
    public function list(string $scope = 'active', ?int $fileId = null, ?string $folder = null): array
    {
        $w = []; $a = [];
        if ($scope === 'active') { $w[] = 'revoked = 0 AND (expires IS NULL OR expires > ?)'; $a[] = time(); }
        if ($fileId !== null) { $w[] = 'file_id = ?'; $a[] = $fileId; }
        if ($folder !== null) { $w[] = 'folder = ?'; $a[] = Library::normalize($folder); }
        $rows = $this->app->store->q('SELECT * FROM shares' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY id DESC LIMIT 300', $a)->fetchAll();
        return array_map(fn($s) => $this->info($s), $rows);
    }

    /** Files and folders with an active link (to mark them in the library). */
    public function sharedIndex(): array
    {
        $rows = $this->app->store->q('SELECT file_id, folder FROM shares WHERE revoked = 0 AND (expires IS NULL OR expires > ?)', [time()])->fetchAll();
        return ['files' => array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'file_id'))))),
            'folders' => array_values(array_unique(array_filter(array_column($rows, 'folder'))))];
    }

    /** Change a link: label, message, days (from now; 0 = no end), password ('' removes it), download, notify. */
    public function update(int $id, array $o): array
    {
        $s = $this->get($id) ?? throw new \InvalidArgumentException('No such link.');
        $set = [];
        if (array_key_exists('label', $o)) $set['label'] = mb_substr(trim((string)$o['label']), 0, 120);
        if (array_key_exists('message', $o)) $set['message'] = mb_substr(trim((string)$o['message']), 0, 2000);
        $level = $this->app->privacy()->folderLevel((string)($s['folder'] ?? '') !== '' ? (string)$s['folder'] : (string)($this->app->files()->get((int)$s['file_id'])['folder'] ?? '/'));
        if (array_key_exists('days', $o)) { $d = (int)$o['days']; if ($d < 0 || $d > 3650) throw new \InvalidArgumentException('days: 0 to 3650');
            if ($level !== 'internal' && ($d === 0 || $d > 30)) throw new \InvalidArgumentException('This belongs to a Confidential space: 30 days at most.');
            $set['expires'] = $d ? time() + $d * 86400 : null; }
        if ($level !== 'internal' && array_key_exists('password', $o) && $o['password'] === '') throw new \InvalidArgumentException('This belongs to a Confidential space: a password is required.');
        if (array_key_exists('password', $o) && $o['password'] !== null) {
            $pw = (string)$o['password'];
            if ($pw !== '' && mb_strlen($pw) < 4) throw new \InvalidArgumentException('The password needs at least 4 characters.');
            $set['password'] = $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null;
        }
        if (array_key_exists('download', $o)) $set['download'] = $o['download'] ? 1 : 0;
        if (array_key_exists('notify', $o)) $set['notify'] = $o['notify'] ? 1 : 0;
        if ($set) $this->app->store->q('UPDATE shares SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
        return $this->get($id);
    }

    public function revoke(int $id): bool
    {
        return $this->app->store->q('UPDATE shares SET revoked = ? WHERE id = ? AND revoked = 0', [time(), $id])->rowCount() > 0;
    }

    /** Forget links revoked or expired more than 90 days ago (with their log). */
    public function purge(): int
    {
        $st = $this->app->store;
        $old = time() - 90 * 86400;
        $ids = array_column($st->q('SELECT id FROM shares WHERE (revoked > 0 AND revoked < ?) OR (expires IS NOT NULL AND expires < ?)', [$old, $old])->fetchAll(), 'id');
        if (!$ids) return 0;
        $in = implode(',', array_map('intval', $ids));
        $st->q("DELETE FROM share_access WHERE share_id IN ($in)");
        $st->q("DELETE FROM shares WHERE id IN ($in)");
        return count($ids);
    }

    /** Who opened a link, newest first. */
    public function accessLog(int $id): array
    {
        return $this->app->store->q('SELECT a.ts, a.ip, a.agent, a.action, a.file_id, f.name FROM share_access a LEFT JOIN files f ON f.id = a.file_id WHERE a.share_id = ? ORDER BY a.id DESC LIMIT 100', [$id])->fetchAll();
    }

    // ---- the visitor's side ----

    /** The link behind a token, if it can still be used. */
    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) return null;
        $s = $this->app->store->q('SELECT * FROM shares WHERE token = ?', [$token])->fetch();
        if (!$s || $s['revoked'] || ($s['expires'] && (int)$s['expires'] < time())) return null;
        return $s;
    }

    /**
     * The files a link opens (one, or the folder's with their subfolders), by id. Checked at every opening, not
     * only when the link was made: a file moved since into a Secret space never shows, and one in a Confidential
     * space only through a link with a password and an end date.
     */
    public function files(array $s): array
    {
        $ids = $s['file_id'] ? [(int)$s['file_id']] : [];
        if (!$s['file_id']) {
            [$w, $a] = Library::within((string)$s['folder']);
            $ids = array_map('intval', array_column($this->app->store->q("SELECT id FROM files WHERE $w ORDER BY folder, name COLLATE NOCASE", $a)->fetchAll(), 'id'));
        }
        $privacy = $this->app->privacy();
        $out = [];
        foreach ($ids as $id) {
            $f = $this->app->files()->get($id);
            if (!$f) continue;
            $level = $privacy->folderLevel((string)$f['folder']);
            if ($level === 'secret' || $level === 'confidential' && ($s['password'] === null || !$s['expires'])) continue;
            $out[$id] = $f;
        }
        return $out;
    }

    /** Is a Secret space's folder inside this one (or this one)? */
    private function secretInside(string $folder): bool
    {
        $in = rtrim($folder, '/') . '/';
        foreach ($this->app->privacy()->strictSpaces() as $sp) {
            if ($sp['privacy'] === 'secret' && $sp['folder'] !== '' && ($sp['folder'] === $folder || str_starts_with($sp['folder'], $in))) return true;
        }
        return false;
    }

    /** Does this visitor have the password (a cookie set after typing it)? */
    public function unlocked(array $s): bool
    {
        if ($s['password'] === null) return true;
        return hash_equals($this->cookieValue($s), (string)($_COOKIE[$this->cookieName($s)] ?? ''));
    }

    /** Check a password typed by a visitor; on success, sets the cookie. Limited tries per address. */
    public function unlock(array $s, string $password): bool
    {
        $st = $this->app->store;
        $key = 'share_tries_' . $s['id'] . '_' . md5(Auth::clientIp());
        [$n, $since] = array_map('intval', explode(':', $st->get($key, '0:0')) + [1 => 0]);
        if ($since < time() - 900) { $n = 0; $since = time(); }
        if ($n >= self::MAX_TRIES) throw new \RuntimeException('Too many wrong passwords. Try again in 15 minutes.');
        if (!password_verify($password, (string)$s['password'])) { $st->set($key, ($n + 1) . ":$since"); return false; }
        $st->set($key, '0:0');
        setcookie($this->cookieName($s), $this->cookieValue($s), ['expires' => time() + 7 * 86400, 'path' => Config::basePath() . '/s/' . $s['token'],
            'httponly' => true, 'samesite' => 'Lax', 'secure' => Auth::secureCookies($this->app->cfg->publicUrl)]);
        return true;
    }

    private function cookieName(array $s): string { return 'tlxs' . $s['id']; }
    private function cookieValue(array $s): string { return hash_hmac('sha256', $s['token'] . '|' . $s['password'], $this->secret()); }

    private function secret(): string
    {
        $k = $this->app->store->get('share_secret');
        if ($k === '') { $k = bin2hex(random_bytes(32)); $this->app->store->set('share_secret', $k); }
        return $k;
    }

    /** Log an opening; the first one is told to the user (if the link asks for it). */
    public function logAccess(array $s, string $action, ?int $fileId = null): void
    {
        $st = $this->app->store;
        $st->q('INSERT INTO share_access(share_id, ts, ip, agent, action, file_id) VALUES(?,?,?,?,?,?)',
            [$s['id'], time(), Auth::clientIp(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $action, $fileId]);
        $st->q('UPDATE shares SET views = views + ?, last_access = ? WHERE id = ?', [$action === 'open' ? 1 : 0, time(), $s['id']]);
        if ($action === 'open' && (int)$s['views'] === 0 && $s['notify']) {
            $i = $this->info($s);
            try {
                $this->app->notifier()->notify(I18n::t($this->app, 'Shared link opened'), I18n::t($this->app, '"{name}"{who} was opened for the first time.',
                    ['name' => $i['name'], 'who' => $i['label'] !== '' ? I18n::t($this->app, ' (for {label})', ['label' => $i['label']]) : '']));
            } catch (\Throwable $e) { Log::warn("share notify: {$e->getMessage()}"); }
        }
    }

    // ---- addresses ----

    /** The app's public address, as last seen from a browser (or TALIXIO_PUBLIC_URL): links work from the worker too. */
    public function baseUrl(): string
    {
        if ($this->app->cfg->publicUrl !== '') return rtrim($this->app->cfg->publicUrl, '/');
        return $this->app->store->get('public_url');
    }

    public static function remember(App $app): void
    {
        $host = Auth::host();
        if ($host === '') return;
        $url = (Auth::https() ? 'https' : 'http') . "://$host" . Config::basePath();
        if ($app->store->get('public_url') !== $url) $app->store->set('public_url', $url);
    }

    private function url(string $token): string { return $this->baseUrl() . '/s/' . $token; }
}
