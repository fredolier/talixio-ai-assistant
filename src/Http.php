<?php
declare(strict_types=1);
namespace Talixio;

final class Http
{
    /** @return array{0:int,1:string,2:array<string,string>} status, body, lowercase headers */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null,
                                   int $timeout = 60, array $curlOpts = []): array
    {
        $resp = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts + [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Talixio/0.2',
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$resp) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException("HTTP error: $err"); }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string)$out, $resp];
    }

    public static function json(string $url, array $payload, array $headers = [], int $timeout = 180): array
    {
        [$code, $body] = self::request('POST', $url, array_merge(['Content-Type: application/json'], $headers),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $timeout);
        $data = json_decode($body, true);
        if ($code >= 400 || !is_array($data)) throw new \RuntimeException("API error $code: " . mb_substr($body, 0, 400));
        return $data;
    }

    /**
     * POST JSON and read a server-sent-events stream. Calls $onEvent(string $event, array $data) per event.
     * Throws with the response body on HTTP errors.
     */
    public static function sse(string $url, array $payload, array $headers, callable $onEvent, int $timeout = 600): void
    {
        $status = 0; $buf = ''; $errBody = ''; $thrown = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'Talixio/0.5',
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: text/event-stream'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status) {
                if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $status = (int)$m[1];
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$status, &$errBody, &$thrown, $onEvent) {
                if ($status >= 400) { $errBody .= $chunk; return strlen($chunk); }
                $buf .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buf, "\n\n")) !== false) {
                    $block = substr($buf, 0, $pos); $buf = substr($buf, $pos + 2);
                    $event = 'message'; $data = '';
                    foreach (explode("\n", $block) as $line) {
                        if (str_starts_with($line, 'event:')) $event = trim(substr($line, 6));
                        elseif (str_starts_with($line, 'data:')) $data .= ltrim(substr($line, 5));
                    }
                    if ($data === '' || $data === '[DONE]') continue;
                    $json = json_decode($data, true);
                    // an exception (the user pressed Stop, an API error event) ends the transfer right away
                    try { if (is_array($json)) $onEvent($event, $json); }
                    catch (\Throwable $e) { $thrown = $e; return 0; }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($thrown) throw $thrown;
        if ($status >= 400) throw new \RuntimeException("API error $status: " . mb_substr($errBody, 0, 400));
        if ($ok === false) throw new \RuntimeException("HTTP error: $err");
    }

    /**
     * Fetch a public URL for the model. Blocks private/loopback/link-local addresses (so a web page
     * cannot trick the assistant into reading your router or cloud metadata), pins the resolved IP
     * against DNS rebinding, and re-checks every redirect.
     */
    public static function safeGet(string $url, int $maxBytes = 2_000_000): array
    {
        for ($hop = 0; $hop < 5; $hop++) {
            $p = parse_url($url);
            $scheme = strtolower($p['scheme'] ?? '');
            $host = $p['host'] ?? '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new \InvalidArgumentException('only http(s) URLs are allowed');
            $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
            $ip = self::publicIp($host);
            [$code, $body, $headers] = self::request('GET', $url, ['Accept: text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5'], null, 20, [
                CURLOPT_RESOLVE => ["$host:$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE => $maxBytes,
            ]);
            if ($code >= 300 && $code < 400 && isset($headers['location'])) {
                $url = self::resolveUrl($url, $headers['location']);
                continue;
            }
            return [$code, $body, $headers['content-type'] ?? '', $url];
        }
        throw new \RuntimeException('too many redirects');
    }

    public static function publicIp(string $host): string
    {
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if (!$ips) throw new \RuntimeException("cannot resolve $host");
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($ip, '100.64.') || str_starts_with(strtolower($ip), 'fe80') || str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd')) {
                throw new \RuntimeException("blocked non-public address for $host");
            }
        }
        return $ips[0];
    }

    private static function resolveUrl(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) return $loc;
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($loc, '//')) return $p['scheme'] . ':' . $loc;
        if (str_starts_with($loc, '/')) return $origin . $loc;
        return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $loc;
    }

    /** Rough HTML to readable text. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|head)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\r\f\v]+/", ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n+/", "\n\n", $text) ?? $text);
    }
}
