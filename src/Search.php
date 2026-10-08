<?php
declare(strict_types=1);
namespace Talixio;

/** Web search backends for providers without built-in search. */
final class Search
{
    public static function run(Config $cfg, string $q): string
    {
        $items = match ($cfg->search) {
            'brave' => self::brave($cfg, $q),
            'tavily' => self::tavily($cfg, $q),
            'searxng' => self::searxng($cfg, $q),
            default => throw new \RuntimeException('search is not configured'),
        };
        if (!$items) return 'no results';
        return implode("\n\n", array_map(fn($r) => "{$r[0]}\n{$r[1]}\n{$r[2]}", array_slice($items, 0, 8)));
    }

    private static function getJson(string $url, array $headers = []): array
    {
        [$code, $body] = Http::request('GET', $url, array_merge(['Accept: application/json'], $headers), null, 20);
        $d = json_decode($body, true);
        if ($code >= 400 || !is_array($d)) throw new \RuntimeException("search error $code");
        return $d;
    }

    private static function brave(Config $cfg, string $q): array
    {
        $d = self::getJson('https://api.search.brave.com/res/v1/web/search?count=8&q=' . rawurlencode($q), ['X-Subscription-Token: ' . $cfg->braveKey]);
        return array_map(fn($r) => [$r['title'] ?? '', $r['url'] ?? '', strip_tags($r['description'] ?? '')], $d['web']['results'] ?? []);
    }

    private static function tavily(Config $cfg, string $q): array
    {
        $d = Http::json('https://api.tavily.com/search', ['query' => $q, 'max_results' => 8], ['Authorization: Bearer ' . $cfg->tavilyKey], 30);
        return array_map(fn($r) => [$r['title'] ?? '', $r['url'] ?? '', $r['content'] ?? ''], $d['results'] ?? []);
    }

    private static function searxng(Config $cfg, string $q): array
    {
        $d = self::getJson($cfg->searxngUrl . '/search?format=json&q=' . rawurlencode($q));
        return array_map(fn($r) => [$r['title'] ?? '', $r['url'] ?? '', $r['content'] ?? ''], $d['results'] ?? []);
    }
}
