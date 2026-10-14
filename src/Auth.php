<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Signing in to the web app: hashed password, limits on failed attempts (per address, no sleeping
 * request holding a server worker), and optional two-step sign-in with an authenticator app (TOTP).
 */
final class Auth
{
    public const MAX_FAILS = 5;         // per address within WINDOW
    public const WINDOW = 900;
    public const MAX_FAILS_DAY = 20;    // per address within a day
    public const MAX_SHARES_HOUR = 10;  // unauthenticated share-target posts per address
    public const MAX_FAILS_ALL = 60;    // from all addresses together within an hour (many addresses, or forged ones)

    public function __construct(private App $app)
    {
        $app->store->db->exec('CREATE TABLE IF NOT EXISTS throttle(k TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS throttle_k ON throttle(k, ts);');
    }

    /**
     * The visitor's address. Behind a trusted reverse proxy (TALIXIO_TRUSTED_PROXIES; by default only one on this
     * machine), the last X-Forwarded-For entry is used: the one that proxy added. Anyone else's header is ignored.
     */
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $fwd = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($fwd !== '' && self::trustedProxy($remote)) {
            $parts = array_map('trim', explode(',', $fwd));
            $last = end($parts); // added by our own proxy; earlier entries can be forged by the client
            if (filter_var($last, FILTER_VALIDATE_IP)) return $last;
        }
        return $remote !== '' ? $remote : 'unknown';
    }

    /** Is this the address of a proxy whose X-Forwarded-* headers we believe? "loopback", "private" or addresses/CIDR, comma-separated. */
    public static function trustedProxy(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $list = trim((string)getenv('TALIXIO_TRUSTED_PROXIES')) ?: 'loopback';
        foreach (preg_split('/[\s,]+/', strtolower($list), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if ($p === 'loopback' ? str_starts_with($ip, '127.') || $ip === '::1'
                : ($p === 'private' ? !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) : AdminWeb::ipAllowed($ip, [$p]))) return true;
        }
        return false;
    }

    /** The host the visitor asked for: X-Forwarded-Host only from a trusted proxy. */
    public static function host(): string
    {
        $fwd = self::trustedProxy((string)($_SERVER['REMOTE_ADDR'] ?? '')) ? (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '') : '';
        return (string)preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', trim(explode(',', $fwd ?: (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
    }

    /** Was the request made over HTTPS (X-Forwarded-Proto only from a trusted proxy)? */
    public static function https(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || self::trustedProxy((string)($_SERVER['REMOTE_ADDR'] ?? '')) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    // --- password ---
    public function hasPassword(): bool { return $this->hash() !== '' || $this->app->cfg->webPassword !== ''; }

    private function hash(): string { return (string)(Settings::load($this->app->cfg->dataDir)['TALIXIO_WEB_PASSWORD_HASH'] ?? ''); }

    /** Checks the password. A password still stored in plain text is replaced by its hash on the first success. */
    public function checkPassword(string $password): bool
    {
        $hash = $this->hash();
        if ($hash !== '') return password_verify($password, $hash);
        $plain = $this->app->cfg->webPassword;
        if ($plain === '' || !hash_equals($plain, $password)) return false;
        Settings::setPassword($this->app->cfg->dataDir, $password);
        return true;
    }

    /** Changes with the password: sessions opened with an older one stop working. */
    public function generation(): string { return substr(hash('sha256', $this->hash() . '|' . $this->app->cfg->webPassword), 0, 16); }

    // --- failed attempts ---
    public function hit(string $key): void { $this->app->store->q('INSERT INTO throttle(k, ts) VALUES(?,?)', [$key, time()]); }

    public function count(string $key, int $seconds): int
    {
        return (int)$this->app->store->q('SELECT COUNT(*) FROM throttle WHERE k = ? AND ts > ?', [$key, time() - $seconds])->fetchColumn();
    }

    public function clear(string $key): void { $this->app->store->q('DELETE FROM throttle WHERE k = ?', [$key]); }

    /** Seconds to wait before this address may try again (0 = allowed). */
    public function lockedFor(string $ip): int
    {
        $this->app->store->q('DELETE FROM throttle WHERE ts < ?', [time() - 86400]);
        foreach ([["login:$ip", self::WINDOW, self::MAX_FAILS], ["login:$ip", 86400, self::MAX_FAILS_DAY], ['login:*', 3600, self::MAX_FAILS_ALL]] as [$key, $window, $max]) {
            if ($this->count($key, $window) >= $max) {
                $oldest = (int)$this->app->store->q('SELECT ts FROM throttle WHERE k = ? AND ts > ? ORDER BY ts LIMIT 1 OFFSET ?',
                    [$key, time() - $window, $this->count($key, $window) - $max])->fetchColumn();
                return max(1, $oldest + $window - time());
            }
        }
        return 0;
    }

    /** Record a failed sign-in; tells the user once when an address gets locked out. */
    public function failed(string $ip): void
    {
        $this->hit("login:$ip");
        $this->hit('login:*');
        if ($this->count('login:*', 3600) === self::MAX_FAILS_ALL) Log::warn('web: sign-in paused for everyone after ' . self::MAX_FAILS_ALL . ' failed attempts within an hour');
        if ($this->count("login:$ip", self::WINDOW) === self::MAX_FAILS) {
            Log::warn("web: sign-in locked for $ip after " . self::MAX_FAILS . ' failed attempts');
            try { $this->app->notifier()->notify('Failed sign-ins', "Someone failed to sign in to {$this->app->cfg->name} " . self::MAX_FAILS . " times from $ip. That address is blocked for a while. If it wasn't you, consider turning on two-step sign-in."); }
            catch (\Throwable $e) { Log::warn("notify: {$e->getMessage()}"); }
        }
    }

    // --- two-step sign-in (TOTP, RFC 6238: 30 s, 6 digits, SHA-1, as in every authenticator app) ---
    public function totpSecret(): string { return (string)(Settings::load($this->app->cfg->dataDir)['TALIXIO_TOTP_SECRET'] ?? ''); }
    public function totpEnabled(): bool { return $this->totpSecret() !== ''; }

    public static function newSecret(): string { return self::base32(random_bytes(20)); }

    public function otpauthUri(string $secret): string
    {
        $label = rawurlencode($this->app->cfg->name) . ':' . rawurlencode(parse_url((string)($_SERVER['HTTP_HOST'] ?? 'web'), PHP_URL_HOST) ?: 'web');
        return "otpauth://totp/$label?secret=$secret&issuer=" . rawurlencode($this->app->cfg->name) . '&period=30&digits=6&algorithm=SHA1';
    }

    /** Checks a code for this secret (one step of clock drift either way). Each code works only once. */
    public function checkCode(string $secret, string $code, bool $remember = true): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code) || $secret === '') return false;
        $now = intdiv(time(), 30);
        $last = (int)$this->app->store->get('totp_last_step', '0');
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($step <= $last || !hash_equals(self::totp($secret, $step), $code)) continue;
            if ($remember) $this->app->store->set('totp_last_step', (string)$step);
            return true;
        }
        return false;
    }

    public static function totp(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function base32(string $bin): string
    {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) $out .= $abc[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=]+/', '', $s))) as $c) {
            $i = strpos($abc, $c);
            if ($i !== false) $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }
}
