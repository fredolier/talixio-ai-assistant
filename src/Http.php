<?php
declare(strict_types=1);
namespace Talixio;

final class Http
{
    /** @return array{0:int,1:string} status, body */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException("HTTP error: $err"); }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string)$out];
    }

    public static function json(string $url, array $payload, array $headers = [], int $timeout = 120): array
    {
        [$code, $body] = self::request('POST', $url, array_merge(['Content-Type: application/json'], $headers),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $timeout);
        $data = json_decode($body, true);
        if ($code >= 400 || !is_array($data)) throw new \RuntimeException("API $code: " . substr($body, 0, 300));
        return $data;
    }
}
