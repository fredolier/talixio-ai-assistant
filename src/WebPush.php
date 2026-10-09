<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Web Push from the installed web app (no third-party service besides the browser's own push service).
 * VAPID (RFC 8292) for identification, aes128gcm payload encryption (RFC 8291). Pure PHP + OpenSSL.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private App $app) {}

    public static function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    /** Raw 65-byte uncompressed public key of an EC key. */
    public static function rawPublic(\OpenSSLAsymmetricKey $k): string
    {
        $ec = openssl_pkey_get_details($k)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new \InvalidArgumentException('bad P-256 public key');
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) ?: throw new \InvalidArgumentException('bad P-256 public key');
    }

    public static function newKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /** VAPID key pair, created on first use and kept in the data directory. */
    public function vapidKey(): \OpenSSLAsymmetricKey
    {
        $file = $this->app->cfg->dataDir . '/vapid.pem';
        if (!is_file($file)) {
            openssl_pkey_export(self::newKey(), $pem);
            file_put_contents($file, $pem, LOCK_EX);
            chmod($file, 0600);
        }
        return openssl_pkey_get_private((string)file_get_contents($file));
    }

    public function publicKey(): string { return self::b64u(self::rawPublic($this->vapidKey())); }

    /** ES256 JWT for the push service's origin. */
    public function vapidJwt(string $endpoint, int $now): string
    {
        $p = parse_url($endpoint);
        $claims = ['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => $now + 12 * 3600, 'sub' => $this->app->cfg->vapidSubject];
        $input = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
        openssl_sign($input, $der, $this->vapidKey(), OPENSSL_ALGO_SHA256);
        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /** DER ECDSA signature -> 64-byte r||s. */
    public static function derToRaw(string $der): string
    {
        $pos = 2; // SEQUENCE, length (signatures are short)
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /** RFC 8291 aes128gcm body for one subscription. */
    public static function encrypt(string $payload, string $uaPublicRaw, string $authSecret, ?\OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        $eph = $ephemeral ?? self::newKey();
        $asPublic = self::rawPublic($eph);
        $ecdh = openssl_pkey_derive(self::publicFromRaw($uaPublicRaw), $eph, 32);
        if ($ecdh === false) throw new \RuntimeException('ECDH failed');
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublicRaw . $asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    public function subscribe(string $endpoint, string $p256dh, string $auth, string $ua): void
    {
        if (!str_starts_with($endpoint, 'https://')) throw new \InvalidArgumentException('push endpoint must be https');
        Http::publicIp((string)parse_url($endpoint, PHP_URL_HOST)); // refuse internal addresses
        self::publicFromRaw(self::unb64u($p256dh));
        if (strlen(self::unb64u($auth)) !== 16) throw new \InvalidArgumentException('bad auth secret');
        $this->app->store->q('INSERT OR REPLACE INTO push_subs(endpoint, p256dh, auth, ua, created) VALUES(?,?,?,?,?)', [$endpoint, $p256dh, $auth, mb_substr($ua, 0, 200), time()]);
    }

    public function unsubscribe(string $endpoint): void { $this->app->store->q('DELETE FROM push_subs WHERE endpoint=?', [$endpoint]); }
    public function count(): int { return (int)$this->app->store->q('SELECT COUNT(*) FROM push_subs')->fetchColumn(); }

    /** Push to every subscribed device. Expired subscriptions are removed. Returns devices reached. */
    public function notify(string $title, string $text, string $url = '/#chat'): int
    {
        $subs = $this->app->store->q('SELECT * FROM push_subs')->fetchAll();
        $payload = json_encode(['title' => $title, 'body' => mb_substr(preg_replace('/[*_`#]/', '', $text), 0, 1500), 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = 0;
        foreach ($subs as $s) {
            try {
                $body = self::encrypt($payload, self::unb64u($s['p256dh']), self::unb64u($s['auth']));
                [$code, $resp] = Http::request('POST', $s['endpoint'], [
                    'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high',
                    'Authorization: vapid t=' . $this->vapidJwt($s['endpoint'], time()) . ', k=' . $this->publicKey(),
                ], $body, 20);
                if ($code === 404 || $code === 410) $this->unsubscribe($s['endpoint']);
                elseif ($code >= 400) Log::warn("web push $code: " . mb_substr($resp, 0, 200));
                else $ok++;
            } catch (\Throwable $e) {
                Log::warn("web push: {$e->getMessage()}");
            }
        }
        return $ok;
    }
}
