<?php
declare(strict_types=1);
namespace Talixio;

/**
 * European AI providers reached through their OpenAI-compatible APIs: Mistral (France), Scaleway (France), OVHcloud
 * (France), Infomaniak (Switzerland). Data is processed in Europe under European law, by companies not subject to the
 * US CLOUD Act. One key serves the conversation model, search by meaning and transcription.
 * Tier models are picked from the models the key can use (cached for a day); explicit TALIXIO_MODEL* settings win.
 */
final class Sovereign
{
    public const SERVICES = [
        'mistral' => ['name' => 'Mistral AI', 'country' => 'France', 'url' => 'https://api.mistral.ai/v1', 'hosts' => ['api.mistral.ai'],
            'terms' => 'https://mistral.ai/terms', 'embed' => 'mistral-embed', 'transcribe' => 'voxtral-mini-latest', 'key' => true,
            'fallback' => ['fast' => 'mistral-small-latest', 'standard' => 'mistral-medium-latest', 'deep' => 'mistral-large-latest'],
            'note' => 'On the free Experiment plan, Mistral may use what you send for training: turn it off in the admin console, or use a paid plan.'],
        'scaleway' => ['name' => 'Scaleway', 'country' => 'France', 'url' => 'https://api.scaleway.ai/v1', 'hosts' => ['api.scaleway.ai'],
            'terms' => 'https://www.scaleway.com/en/contracts/', 'embed' => 'qwen3-embedding-8b', 'transcribe' => 'whisper-large-v3', 'key' => true,
            'fallback' => ['fast' => 'mistral-small-3.2-24b-instruct-2506', 'standard' => 'gpt-oss-120b', 'deep' => 'qwen3-235b-a22b-instruct-2507'], 'note' => ''],
        'ovhcloud' => ['name' => 'OVHcloud AI Endpoints', 'country' => 'France', 'url' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1', 'hosts' => ['.endpoints.kepler.ai.cloud.ovh.net'],
            'terms' => 'https://www.ovhcloud.com/en/terms-and-conditions/contracts/', 'embed' => 'bge-m3', 'transcribe' => 'whisper-large-v3', 'key' => false, // works without a key, with lower limits
            'fallback' => ['fast' => 'Mistral-Small-3.2-24B-Instruct-2506', 'standard' => 'gpt-oss-120b', 'deep' => 'gpt-oss-120b'], 'note' => ''],
        'infomaniak' => ['name' => 'Infomaniak', 'country' => 'Switzerland', 'url' => '', 'hosts' => ['api.infomaniak.com'], // the address holds your product id
            'terms' => 'https://www.infomaniak.com/en/legal', 'embed' => 'bge_multilingual_gemma2', 'transcribe' => 'whisper', 'key' => true,
            'fallback' => ['fast' => 'mistral3', 'standard' => 'qwen3', 'deep' => 'qwen3'], 'note' => ''],
    ];

    /** Not a chat model: vectors, audio, moderation, OCR, code-only. */
    private const NOT_CHAT = '/embed|bge|e5-|minilm|rerank|whisper|voxtral|transcri|tts|audio|speech|guard|moderation|ocr|pixtral|codestral|devstral|diffusion|flux|stable|sdxl|image/i';

    public static function info(string $service): array { return self::SERVICES[$service] ?? self::SERVICES['mistral']; }

    /** @param string[] $ids model ids as listed @return array<string,string> tier => model */
    public static function choose(string $service, array $ids): array
    {
        $fb = self::info($service)['fallback'];
        $chat = array_values(array_filter($ids, fn($i) => !preg_match(self::NOT_CHAT, $i)));
        if (!$chat) return $fb;
        if ($service === 'mistral') {
            $pick = function (string $family) use ($chat): ?string {
                if (in_array("mistral-$family-latest", $chat, true)) return "mistral-$family-latest"; // Mistral's moving alias
                $c = array_filter($chat, fn($i) => preg_match('/^mistral-' . $family . '-\d{4}$/', $i));
                rsort($c);
                return $c[0] ?? null;
            };
            $small = $pick('small'); $medium = $pick('medium'); $large = $pick('large');
            return ['fast' => $small ?? $medium ?? $fb['fast'], 'standard' => $medium ?? $large ?? $small ?? $fb['standard'], 'deep' => $large ?? $medium ?? $fb['deep']];
        }
        // the others serve open models: rank them by size (parameters in the name; a mixture of experts by its total)
        $sized = [];
        foreach ($chat as $id) if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)b(?![a-z])/i', $id, $m)) $sized[$id] = (float)$m[1];
        if (!$sized) return array_map(fn($m) => in_array($m, $chat, true) ? $m : $chat[0], $fb);
        asort($sized);
        $names = array_keys($sized);
        $fast = $names[0];
        foreach ($names as $n) if ($sized[$n] >= 12) { $fast = $n; break; } // the smallest that still follows instructions well
        $deep = end($names);
        $std = null;
        foreach ($names as $n) if (preg_match('/gpt-oss-120b/i', $n)) $std = $n; // good with tools, fast for its size
        $std ??= $names[intdiv(count($names), 2)];
        return ['fast' => $fast, 'standard' => $std, 'deep' => $deep];
    }

    /** Fill in tier models that aren't set explicitly. */
    public static function apply(Config $c, Store $store): void
    {
        $missing = array_filter($c->tiers, fn(Tier $t) => $t->model === '');
        if (!$missing) return;
        $fb = self::info($c->sovereign)['fallback'];
        $id = $c->sovereign . '|' . $c->sovereignUrl;
        $cached = json_decode($store->get('sovereign_models'), true);
        if (!is_array($cached) || ($cached['id'] ?? '') !== $id || ($cached['ts'] ?? 0) < time() - 86400) {
            try {
                if ($c->sovereignUrl === '') throw new \RuntimeException('no address');
                [$code, $body] = Http::request('GET', $c->sovereignUrl . '/models', $c->sovereignKey !== '' ? ['Authorization: Bearer ' . $c->sovereignKey] : [], null, 15);
                $ids = array_column(json_decode($body, true)['data'] ?? [], 'id');
                if ($code >= 400 || !$ids) throw new \RuntimeException("HTTP $code");
                $cached = ['id' => $id, 'ts' => time(), 'tiers' => self::choose($c->sovereign, $ids)];
                $store->set('sovereign_models', json_encode($cached));
            } catch (\Throwable $e) {
                Log::warn("{$c->sovereign}: could not list models ({$e->getMessage()}); using " . implode(', ', $fb));
                $cached = ['tiers' => $fb];
            }
        }
        foreach ($missing as $name => $t) $t->model = $cached['tiers'][$name] ?? $fb[$name];
    }

    /** The service a URL belongs to (null: not a European provider). */
    public static function serviceOf(string $url): ?string
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        foreach (self::SERVICES as $id => $s) {
            foreach ($s['hosts'] as $h) if ($host === $h || (str_starts_with($h, '.') && str_ends_with($host, $h))) return $id;
        }
        return null;
    }
}
