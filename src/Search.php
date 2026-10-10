<?php
declare(strict_types=1);
namespace Talixio;

/** Web search backends for providers without built-in search. */
final class Search
{
    public static function run(Config $cfg, string $q): string
    {
        return Privacy::as('search', fn() => self::runNow($cfg, $q));
    }

    private static function runNow(Config $cfg, string $q): string
    {
        $items = match ($cfg->search) {
            'brave' => self::brave($cfg, $q),
            'tavily' => self::tavily($cfg, $q),
            'searxng' => self::searxng($cfg, $q),
            'gemini' => self::gemini($cfg, $q),
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

    /** Gemini's native "Grounding with Google Search" (same API key), on the fast tier's model. */
    private static function gemini(Config $cfg, string $q): array
    {
        $base = $cfg->geminiBaseUrl;
        $model = $cfg->provider === 'gemini' ? preg_replace('#^models/#', '', $cfg->tier('fast')->model ?: GeminiModels::FALLBACK['fast']) : GeminiModels::FALLBACK['standard'];
        $d = Http::json("$base/models/$model:generateContent", [
            'contents' => [['parts' => [['text' => "Search the web and give a factual summary with key details (dates, numbers, names) for: $q"]]]],
            'tools' => [['google_search' => new \stdClass()]],
        ], ['x-goog-api-key: ' . $cfg->geminiKey], 60);
        $cand = $d['candidates'][0] ?? [];
        $text = implode('', array_column($cand['content']['parts'] ?? [], 'text'));
        $items = [['Search summary (Google, via Gemini)', '', $text]];
        foreach ($cand['groundingMetadata']['groundingChunks'] ?? [] as $c) {
            if (isset($c['web']['uri'])) $items[] = [$c['web']['title'] ?? '', $c['web']['uri'], ''];
        }
        return $text === '' && count($items) === 1 ? [] : $items;
    }

    private static function searxng(Config $cfg, string $q): array
    {
        $d = self::getJson($cfg->searxngUrl . '/search?format=json&q=' . rawurlencode($q));
        return array_map(fn($r) => [$r['title'] ?? '', $r['url'] ?? '', $r['content'] ?? ''], $d['results'] ?? []);
    }
}
