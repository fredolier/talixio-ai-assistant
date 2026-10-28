<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The catalogue behind the "Modèle et budget" page: every provider the assistant knows about (paid, free, sovereign
 * or local) and the models each one serves, with a capability rank (1 basic → 10 flagship), indicative prices, a type
 * (LLM / vision / voice / image) and a trust level (TL). The Modèles table is built from this, and a model is picked
 * per usage through the routing engine (Routing).
 *
 * Today the list is a hand-kept seed. A connected provider (the main AI settings, or an AI account) is flagged
 * `connected` so the page can show "available" models first; a weekly job (slice 3) will fold in the live model lists.
 *
 * Trust levels: 1 consumer (e.g. China) · 2 free / public · 3 business · 4 business, no retention · 5 sovereign (GDPR)
 * · 'L' local (on your own machine). 5 and 'L' are what the Europe (GDPR) lock keeps.
 */
final class ModelCatalog
{
    /** Seed providers. `kind`/`country` are English keys translated in the UI; `flag` and `bill` are data. */
    private const PROVIDERS = [
        ['id' => 'mistral',    'name' => 'Mistral AI',  'country' => 'France',        'flag' => '🇫🇷', 'tl' => 5,   'kind' => 'Sovereign (GDPR)',        'bill' => 'usage'],
        ['id' => 'anthropic',  'name' => 'Anthropic',   'country' => 'United States', 'flag' => '🇺🇸', 'tl' => 4,   'kind' => 'Business, no retention',  'bill' => 'usage'],
        ['id' => 'openai',     'name' => 'OpenAI',      'country' => 'United States', 'flag' => '🇺🇸', 'tl' => 3,   'kind' => 'Business',                'bill' => 'usage'],
        ['id' => 'google',     'name' => 'Google',      'country' => 'United States', 'flag' => '🇺🇸', 'tl' => 3,   'kind' => 'Business',                'bill' => 'usage', 'auth' => 'oauth'],
        ['id' => 'scaleway',   'name' => 'Scaleway',    'country' => 'France',        'flag' => '🇫🇷', 'tl' => 5,   'kind' => 'Sovereign (GDPR)',        'bill' => 'usage'],
        ['id' => 'ovhcloud',   'name' => 'OVHcloud AI', 'country' => 'France',        'flag' => '🇫🇷', 'tl' => 5,   'kind' => 'Sovereign (GDPR)',        'bill' => 'usage'],
        ['id' => 'infomaniak', 'name' => 'Infomaniak',  'country' => 'Switzerland',   'flag' => '🇨🇭', 'tl' => 5,   'kind' => 'Sovereign (GDPR)',        'bill' => 'usage'],
        ['id' => 'aki',        'name' => 'AKI.IO',      'country' => 'Germany',       'flag' => '🇪🇺', 'tl' => 5,   'kind' => 'Sovereign, no retention', 'bill' => 'usage'],
        ['id' => 'elevenlabs', 'name' => 'ElevenLabs',  'country' => 'United States', 'flag' => '🇺🇸', 'tl' => 3,   'kind' => 'Business',                'bill' => 'usage'],
        ['id' => 'deepseek',   'name' => 'DeepSeek',    'country' => 'China',         'flag' => '🇨🇳', 'tl' => 1,   'kind' => 'Consumer',                'bill' => 'usage'],
        ['id' => 'huggingface','name' => 'Hugging Face','country' => 'Multiple',      'flag' => '🤗', 'tl' => 2,    'kind' => 'Free / public',           'bill' => 'free'],
        ['id' => 'local',      'name' => 'Local machine','country' => 'This computer','flag' => '🖥️', 'tl' => 'L', 'kind' => 'Local',                   'bill' => 'free'],
    ];

    /**
     * Seed models. LLM/vision carry `pin`/`pout` ($/million tokens; 0 = free). Voice/image carry `unit` (price per
     * minute or image; 0 = free), `unitKind` ('min'|'img') and `cur` ('$'|'€'). `est` marks a price that is an
     * estimate (the provider bills in another currency, or the figure could not be confirmed); `deprecated` marks a
     * model the provider is retiring. Prices are indicative — check the provider. Ranks are relative capability.
     * Figures gathered Oct 2026 (Anthropic from the model's own pricing; the rest from public pricing trackers).
     */
    private const MODELS = [
        // Anthropic (Claude 5 family) — first-party prices (≤100K-token tier for Haiku). `id` is the API model string.
        ['name' => 'Haiku 5.5',  'id' => 'claude-haiku-5-5',  'prov' => 'anthropic', 'type' => 'LLM', 'rank' => 6,  'pin' => 0.10, 'pout' => 0.50],
        ['name' => 'Sonnet 5.5', 'id' => 'claude-sonnet-5-5', 'prov' => 'anthropic', 'type' => 'LLM', 'rank' => 8,  'pin' => 2.00, 'pout' => 10.0],
        ['name' => 'Opus 5.5',   'id' => 'claude-opus-5-5',   'prov' => 'anthropic', 'type' => 'LLM', 'rank' => 10, 'pin' => 4.00, 'pout' => 20.0],
        ['name' => 'Fable 5.1',  'id' => 'claude-fable-5-1',  'prov' => 'anthropic', 'type' => 'LLM', 'rank' => 9,  'pin' => 10.0, 'pout' => 50.0],
        // OpenAI
        ['name' => 'gpt-5.1',      'prov' => 'openai', 'type' => 'LLM', 'rank' => 9, 'pin' => 1.25, 'pout' => 10.0, 'est' => true],
        ['name' => 'gpt-5.1-mini', 'prov' => 'openai', 'type' => 'LLM', 'rank' => 6, 'pin' => 0.25, 'pout' => 2.00, 'est' => true],
        // Google
        ['name' => 'gemini-2.5-pro',   'prov' => 'google', 'type' => 'LLM', 'rank' => 9, 'pin' => 1.25, 'pout' => 10.0, 'est' => true],
        ['name' => 'gemini-2.5-flash', 'prov' => 'google', 'type' => 'LLM', 'rank' => 7, 'pin' => 0.30, 'pout' => 2.50, 'est' => true],
        // Mistral (France) — billed in EUR, shown as USD-equivalent
        ['name' => 'mistral-small-latest',  'prov' => 'mistral', 'type' => 'LLM',    'rank' => 5, 'pin' => 0.15, 'pout' => 0.60, 'est' => true],
        ['name' => 'mistral-medium-latest', 'prov' => 'mistral', 'type' => 'LLM',    'rank' => 7, 'pin' => 1.50, 'pout' => 7.50, 'est' => true],
        ['name' => 'mistral-large-latest',  'prov' => 'mistral', 'type' => 'LLM',    'rank' => 8, 'pin' => 0.50, 'pout' => 1.50, 'est' => true],
        ['name' => 'pixtral-large',         'prov' => 'mistral', 'type' => 'Vision', 'rank' => 7, 'pin' => 2.00, 'pout' => 6.00, 'est' => true, 'deprecated' => true],
        ['name' => 'Voxtral',               'prov' => 'mistral', 'type' => 'Voice',  'rank' => 5, 'unit' => 0.003, 'unitKind' => 'min', 'cur' => '$', 'est' => true],
        // Scaleway (France) — billed in EUR
        ['name' => 'Llama 3.3 70B',  'prov' => 'scaleway', 'type' => 'LLM',   'rank' => 7, 'pin' => 0.90, 'pout' => 0.90, 'est' => true],
        ['name' => 'FLUX.1 [pro]',   'prov' => 'scaleway', 'type' => 'Image', 'rank' => 7, 'unit' => 0.05, 'unitKind' => 'img', 'cur' => '$', 'est' => true],
        // OVHcloud (France) — billed in EUR
        ['name' => 'Qwen2.5 72B',  'prov' => 'ovhcloud', 'type' => 'LLM', 'rank' => 7, 'pin' => 0.90, 'pout' => 0.90, 'est' => true],
        ['name' => 'gpt-oss-120b', 'prov' => 'ovhcloud', 'type' => 'LLM', 'rank' => 7, 'pin' => 0.09, 'pout' => 0.47, 'est' => true],
        // Infomaniak (Switzerland) — billed in CHF
        ['name' => 'Apertus 70B',    'prov' => 'infomaniak', 'type' => 'LLM', 'rank' => 6, 'pin' => 0.87, 'pout' => 3.10, 'est' => true],
        ['name' => 'Ministral 3 14B','prov' => 'infomaniak', 'type' => 'LLM', 'rank' => 5, 'pin' => 0.33, 'pout' => 0.44, 'est' => true],
        // AKI.IO (Germany) — TL5 models processed in Germany
        ['name' => 'GPT-OSS 120B',     'prov' => 'aki', 'type' => 'LLM',   'rank' => 7, 'pin' => 0.15, 'pout' => 0.55, 'est' => true],
        ['name' => 'MiniMax M2.5 230B','prov' => 'aki', 'type' => 'LLM',   'rank' => 8, 'pin' => 0.25, 'pout' => 1.20, 'est' => true],
        ['name' => 'Apertus 70B (AKI)','prov' => 'aki', 'type' => 'LLM',   'rank' => 6, 'pin' => 0.80, 'pout' => 3.00, 'est' => true],
        ['name' => 'Flux.2 [klein]',   'prov' => 'aki', 'type' => 'Image', 'rank' => 5, 'unit' => 0.015, 'unitKind' => 'img', 'cur' => '€', 'est' => true],
        // DeepSeek (China) — peak/off-peak pricing; off-peak shown
        ['name' => 'deepseek-chat', 'prov' => 'deepseek', 'type' => 'LLM', 'rank' => 8, 'pin' => 0.28, 'pout' => 0.42, 'est' => true],
        // ElevenLabs — voice, billed per character (≈ per minute shown)
        ['name' => 'ElevenLabs v3', 'prov' => 'elevenlabs', 'type' => 'Voice', 'rank' => 7, 'unit' => 0.10, 'unitKind' => 'min', 'cur' => '$', 'est' => true],
        // Local (Ollama / llama.cpp) — no marginal cost
        ['name' => 'Llama 3.1 8B (local)', 'prov' => 'local', 'type' => 'LLM',   'rank' => 3, 'pin' => 0, 'pout' => 0],
        ['name' => 'Qwen2.5 7B (local)',   'prov' => 'local', 'type' => 'LLM',   'rank' => 4, 'pin' => 0, 'pout' => 0],
        ['name' => 'Whisper (local)',      'prov' => 'local', 'type' => 'Voice', 'rank' => 3, 'unit' => 0, 'unitKind' => 'min', 'cur' => '$'],
    ];

    /** Which seed-provider id a Config (main settings or an AI account) counts as being connected to. */
    public const BY_PROVIDER = ['anthropic' => 'anthropic', 'claude-code' => 'anthropic', 'openai' => 'openai', 'gemini' => 'google'];

    public function __construct(private App $app) {}

    /**
     * The whole catalogue for the page: providers (each with the `sources` that can serve it — the credentials a
     * model of that provider would be routed through — and a `connected` flag) and models (each flagged `available`
     * when its provider has a source). A source is {account, name}: account '' is the main settings, else an AI
     * account id. `updated`/`source` describe where the list came from.
     * @return array{providers:list<array>, models:list<array>, updated:int, source:string}
     */
    public function catalogue(): array
    {
        $sources = $this->sources();
        $providers = array_map(fn(array $p) => $p + ['sources' => $sources[$p['id']] ?? [], 'connected' => !empty($sources[$p['id']])], self::PROVIDERS);
        $models = array_map(fn(array $m) => $m + ['available' => !empty($sources[$m['prov']])], self::MODELS);
        return ['providers' => array_values($providers), 'models' => array_values($models),
            'updated' => (int)$this->app->store->get('catalogue_updated', (string)time()), 'source' => 'seed'];
    }

    /**
     * For each seed-provider id, the credential sources that can serve it (the main settings plus any AI account on
     * that provider). @return array<string,list<array{account:string,name:string}>>
     */
    public function sources(): array
    {
        $out = [];
        $add = function (?Config $c, string $account, string $name) use (&$out) {
            if ($c === null || ($id = self::providerOf($c)) === null) return;
            $out[$id][] = ['account' => $account, 'name' => $name];
        };
        $add($this->app->mainCfg, '', 'Main settings');
        foreach ($this->app->accounts()->all() as $a) $add($this->app->accountConfig($a['id']), $a['id'], $a['name']);
        return $out;
    }

    /** The seed-provider ids the assistant can reach right now. @return list<string> */
    public function connected(): array { return array_keys($this->sources()); }

    /** The seed-provider id a Config is served by (null when it isn't one of the catalogue's providers). */
    public static function providerOf(Config $c): ?string
    {
        if ($c->localModels()) return 'local';
        if ($c->provider === 'sovereign') return $c->sovereign;
        return self::BY_PROVIDER[$c->provider] ?? null;
    }

    /** The seed provider ids, for validation elsewhere. @return list<string> */
    public static function providerIds(): array { return array_column(self::PROVIDERS, 'id'); }
}
