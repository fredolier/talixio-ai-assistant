<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Secrets at rest (audit S22, 7.3): API keys, passwords, sign-in tokens are kept encrypted (sodium secretbox) with a key
 * that does not live in the data folder, so a copy of that folder (a backup, a stolen disk, a volume) does not give them.
 *
 * The key: TALIXIO_SECRET_KEY (64 hex characters, how a multi-user server hands each assistant its own), else the file
 * TALIXIO_SECRET_KEY_FILE (default /etc/talixio/secret.key, made by install.sh). Without a key, values stay as they
 * were (plain), and the doctor says so. A sealed value reads "enc:v1:<base64>"; a plain one is read as it is, and is
 * sealed when next written (migrate() does it for everything at the worker's start).
 * A transfer to another server carries the key of the old one (.transfer-key in the data folder) until migrate()
 * seals everything again with the new server's key and removes it.
 */
final class Secrets
{
    public const PREFIX = 'enc:v1:';
    private const SEALED = 'talixio_sealed'; // a whole JSON file sealed: {"talixio_sealed": "enc:v1:..."}
    public const TRANSFER_KEY = '.transfer-key';

    /** @var array<string,?string> key file or env => the 32-byte key, read once */
    private static array $cache = [];
    /** Data folder whose .transfer-key may open values sealed on another server (set by App). */
    public static string $dataDir = '';

    public static function keyFile(): string { return (string)(getenv('TALIXIO_SECRET_KEY_FILE') ?: '/etc/talixio/secret.key'); }

    /** The key in use, or null when there is none (or it is not a key). */
    public static function key(): ?string
    {
        if (!function_exists('sodium_crypto_secretbox')) return null; // without the extension: kept as before, the doctor says it
        $env = (string)getenv('TALIXIO_SECRET_KEY');
        $id = $env !== '' ? 'env:' . hash('sha256', $env) : 'file:' . self::keyFile();
        if (array_key_exists($id, self::$cache)) return self::$cache[$id];
        $raw = $env !== '' ? $env : (is_readable(self::keyFile()) ? (string)@file_get_contents(self::keyFile()) : '');
        return self::$cache[$id] = self::parse($raw);
    }

    private static function parse(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^[0-9a-f]{64}$/i', $raw)) return (string)hex2bin($raw);
        return null;
    }

    /** Forget the keys read (tests, and after a transfer key is removed). */
    public static function reset(): void { self::$cache = []; }

    public static function enabled(): bool { return self::key() !== null; }

    /** A new key, as written to a key file or given in TALIXIO_SECRET_KEY. */
    public static function newKey(): string { return bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); }

    public static function sealed(string $v): bool { return str_starts_with($v, self::PREFIX); }

    /** $plain encrypted with the key; unchanged when there is no key, when it is empty or already sealed. */
    public static function seal(string $plain): string
    {
        $k = self::key();
        if ($k === null || $plain === '' || self::sealed($plain)) return $plain;
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $k));
    }

    /**
     * The plain value: a sealed one opened with the key (or the key a transfer brought), a plain one as it is.
     * A sealed value no key opens is given back as it is (and the log warns): it does not work as a secret, but saving
     * anything else never loses it, so it opens again once the right key is back (or it is entered again).
     */
    public static function open(string $v): string
    {
        if (!self::sealed($v)) return $v;
        $p = self::tryOpen($v);
        if ($p !== null) return $p[0];
        if (self::$unreadable++ === 0) Log::warn('secrets: a saved secret cannot be read with this server\'s key (' . (self::key() === null ? 'no key: ' . self::keyFile() . ' is missing' : 'another key sealed it') . '); put the right key back, or enter the secret again');
        return $v;
    }

    /** @return ?array{0:string,1:int} the plain value and which key opened it (0: this server's, 1: a transfer's) */
    private static function tryOpen(string $v): ?array
    {
        $bin = base64_decode(substr($v, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $keys = self::keys();
        foreach ($keys as $i => $k) {
            $p = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
            if ($p !== false) return [$p, $i === 0 && self::key() !== null ? 0 : 1];
        }
        return null;
    }

    /** Values that could not be opened since the start (the doctor shows it; the log says it once). */
    public static int $unreadable = 0;

    /** @return list<string> the key, then the one a transfer brought */
    private static function keys(): array
    {
        $out = [];
        if (($k = self::key()) !== null) $out[] = $k;
        if (($t = self::transferKey()) !== null && $t !== ($out[0] ?? null)) $out[] = $t;
        return $out;
    }

    private static function transferKey(): ?string
    {
        $f = self::$dataDir . '/' . self::TRANSFER_KEY;
        return self::$dataDir !== '' && is_readable($f) ? self::parse((string)@file_get_contents($f)) : null;
    }

    /** The key a transfer hands to the new server (hex): this server's, or the one a transfer brought here and not used yet. */
    public static function exportKey(): ?string
    {
        $k = self::key() ?? self::transferKey();
        return $k === null ? null : bin2hex($k);
    }

    /** A whole array kept as one sealed value ({"talixio_sealed": ...}), or as it is without a key. */
    public static function sealArray(array $d): array
    {
        if (!self::enabled() || !$d) return $d;
        return [self::SEALED => self::seal(json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    public static function openArray(array $d): array
    {
        if (count($d) !== 1 || !is_string($d[self::SEALED] ?? null)) return $d;
        $o = json_decode(self::open($d[self::SEALED]), true);
        return is_array($o) ? $o : [];
    }

    /** The values of $keys in $d sealed (or opened), the rest as it is. */
    public static function sealKeys(array $d, array $keys, bool $seal = true): array
    {
        foreach ($keys as $k) if (isset($d[$k]) && is_string($d[$k])) $d[$k] = $seal ? self::seal($d[$k]) : self::open($d[$k]);
        return $d;
    }

    /**
     * Everything kept sealed with this server's key: plain values from before (or from a hand edit) are sealed, values
     * a transfer brought sealed with the old server's key are sealed again with this one, then that key is removed.
     * Run at the worker's start. Returns how many stores were written.
     */
    public static function migrate(App $app): int
    {
        $n = 0;
        $dir = $app->cfg->dataDir;
        $transfer = is_file("$dir/" . self::TRANSFER_KEY);
        if (!self::enabled() && !$transfer) return 0;
        try {
            $n += Settings::reseal($dir) ? 1 : 0;
            foreach (self::FILES as $pat) foreach (glob("$dir/$pat") ?: [] as $f) {
                $raw = JsonFile::read($f, false);
                if ($raw === [] || !self::stale(is_string($raw[self::SEALED] ?? null) && count($raw) === 1 ? $raw[self::SEALED] : 'plain')) continue;
                JsonFile::update($f, fn(array $d) => [$d, null], 0600, 0, true); $n++;
            }
            $n += $app->mcp()->reseal() ? 1 : 0;
            $n += $app->accounts()->reseal() ? 1 : 0;
            $n += $app->federation()->reseal();
        } catch (\Throwable $e) {
            Log::warn('secrets: could not seal everything: ' . $e->getMessage());
            return $n; // the transfer key stays until it works
        }
        if ($transfer) { @unlink("$dir/" . self::TRANSFER_KEY); self::reset(); Log::info('secrets: the secrets that came with the transfer are now sealed with this server\'s key'); }
        if ($n) Log::info("secrets: $n store(s) sealed with this server's key");
        return $n;
    }

    /** The files kept whole and sealed (sign-in tokens, the pilot's binding). */
    public const FILES = ['google.json', 'microsoft*.json', 'spotify.json', 'piste.json', 'mcp-oauth.json', 'conductor.json'];

    /**
     * Whether writing a saved value again would change how it is kept: plain while there is a key, or sealed with the key
     * a transfer brought. A value no key opens is not (writing it again cannot help).
     */
    public static function stale(string $v): bool
    {
        if ($v === '') return false;
        if (!self::sealed($v)) return self::key() !== null;
        $p = self::tryOpen($v);
        return $p !== null && $p[1] === 1;
    }
}
