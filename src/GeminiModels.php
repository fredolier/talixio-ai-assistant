<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Picks Gemini models for the three tiers from the models your API key can use (cached for a day),
 * so new Gemini releases are picked up without editing the config. Explicit TALIXIO_MODEL* settings win.
 */
final class GeminiModels
{
    public const FALLBACK = ['fast' => 'gemini-2.5-flash-lite', 'standard' => 'gemini-2.5-flash', 'deep' => 'gemini-2.5-pro'];

    /** @param string[] $ids model ids as listed (with or without "models/") @return array<string,string> tier => model */
    public static function choose(array $ids): array
    {
        $ids = array_map(fn($i) => preg_replace('#^models/#', '', $i), $ids);
        $chat = array_filter($ids, fn($i) => str_starts_with($i, 'gemini-')
            && !preg_match('/embedding|tts|image|audio|live|vision|computer-use|robotics|aqa|thinking-exp|-exp\b|-\d{3,}$|customtools/i', $i));
        $pick = function (string $kind) use ($chat): ?string {
            if (in_array("gemini-$kind-latest", $chat, true)) return "gemini-$kind-latest"; // Google's moving alias
            $best = null; $bestKey = null;
            foreach ($chat as $id) {
                if (!preg_match('/^gemini-(\d+(?:\.\d+)?)-' . preg_quote($kind, '/') . '(-preview(?:-[\w-]+)?)?$/', $id, $m)) continue;
                $key = [(float)$m[1], empty($m[2]) ? 1 : 0, $id]; // newest version, then stable over preview
                if ($bestKey === null || $key > $bestKey) { $bestKey = $key; $best = $id; }
            }
            return $best;
        };
        return [
            'fast' => $pick('flash-lite') ?? $pick('flash') ?? self::FALLBACK['fast'],
            'standard' => $pick('flash') ?? self::FALLBACK['standard'],
            'deep' => $pick('pro') ?? $pick('flash') ?? self::FALLBACK['deep'],
        ];
    }

    /** Fill in tier models that aren't set explicitly. */
    public static function apply(Config $c, Store $store): void
    {
        $missing = array_filter($c->tiers, fn(Tier $t) => $t->model === '');
        if (!$missing) return;
        $cached = json_decode($store->get('gemini_models'), true);
        if (!is_array($cached) || ($cached['ts'] ?? 0) < time() - 86400) {
            try {
                [$code, $body] = Http::request('GET', $c->openaiBaseUrl . '/models', ['Authorization: Bearer ' . $c->openaiKey], null, 15);
                $ids = array_column(json_decode($body, true)['data'] ?? [], 'id');
                if ($code >= 400 || !$ids) throw new \RuntimeException("HTTP $code");
                $cached = ['ts' => time(), 'tiers' => self::choose($ids)];
                $store->set('gemini_models', json_encode($cached));
            } catch (\Throwable $e) {
                Log::warn("gemini: could not list models ({$e->getMessage()}); using " . implode(', ', self::FALLBACK));
                $cached = ['tiers' => self::FALLBACK];
            }
        }
        foreach ($missing as $name => $t) $t->model = $cached['tiers'][$name] ?? self::FALLBACK[$name];
    }
}
