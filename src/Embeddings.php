<?php
declare(strict_types=1);
namespace Talixio;

/** Text embeddings for semantic memory search. Backends: ollama (local), openai, voyage. */
final class Embeddings
{
    /**
     * The choices shown in Settings → Search by meaning. Local ones run in Ollama (on this server, or the stack's shared
     * one); memory figures are what Ollama holds while it computes, approximate.
     */
    public const PRESETS = [
        'local-tiny' => ['backend' => 'ollama', 'model' => 'hf.co/TwinSunsLLC/multilingual-e5-small-gguf:Q8_0', 'memory_mb' => 300, 'speed' => 5,
            'label' => 'On this server: very light', 'about' => 'multilingual-e5-small (118 M parameters, community conversion). About 5 times lighter than the quality model; a little less precise, in French too.'],
        'local-light' => ['backend' => 'ollama', 'model' => 'embeddinggemma', 'memory_mb' => 700, 'speed' => 2,
            'label' => 'On this server: light', 'about' => 'EmbeddingGemma (300 M parameters, Google, multilingual). About twice as light as the quality model, close to it in quality.'],
        'local-quality' => ['backend' => 'ollama', 'model' => 'qwen3-embedding:0.6b', 'memory_mb' => 1500, 'speed' => 1,
            'label' => 'On this server: best quality', 'about' => 'Qwen3-Embedding 0.6B: the most precise local model, good in French. Wants a recent processor (AVX2) and 1.5 GB.'],
        'gemini' => ['backend' => 'gemini', 'model' => 'gemini-embedding-001', 'memory_mb' => 0, 'speed' => 0,
            'label' => 'Google Gemini', 'about' => 'Nothing to compute here. Needs the Gemini key; on the free tier Google may use the texts to improve its models.'],
        'openai' => ['backend' => 'openai', 'model' => 'text-embedding-3-small', 'memory_mb' => 0, 'speed' => 0,
            'label' => 'OpenAI', 'about' => 'Nothing to compute here. Needs the OpenAI key; a few cents a month.'],
        'voyage' => ['backend' => 'voyage', 'model' => 'voyage-3.5-lite', 'memory_mb' => 0, 'speed' => 0,
            'label' => 'Voyage AI', 'about' => 'Nothing to compute here. Needs a Voyage key; a free allowance, then a few cents a month.'],
        'sovereign' => ['backend' => 'sovereign', 'model' => '', 'memory_mb' => 0, 'speed' => 0,
            'label' => 'European provider', 'about' => 'Nothing to compute here. Uses the key of the European provider (Mistral, Scaleway, OVHcloud, Infomaniak or AKI.IO): the texts stay in Europe; allowed for Confidential spaces.'],
        'off' => ['backend' => 'off', 'model' => '', 'memory_mb' => 0, 'speed' => 0,
            'label' => 'Off', 'about' => 'Search by words only. Nothing is computed or sent.'],
    ];

    /** The preset matching the current settings, or 'custom'. */
    public static function presetOf(Config $c): string
    {
        foreach (self::PRESETS as $k => $p) if ($p['backend'] === $c->embeddings && ($p['model'] === '' || $p['model'] === $c->embeddingsModel)) return $k;
        return 'custom';
    }

    /** @var callable|null test hook: fn(array $texts, string $type): array<array<float>> */
    public $fake = null;

    public function __construct(private Config $cfg) {}

    /** Computed on this server (Ollama here), so any text may be indexed. */
    public function local(App $app): bool { return $this->fake !== null || ($this->cfg->embeddings === 'ollama' && Privacy::isLocalHost((string)parse_url($this->cfg->ollamaUrl, PHP_URL_HOST))); }

    public function enabled(): bool { return $this->fake !== null || $this->cfg->embeddings !== 'off'; }
    /** Identifies the vectors: changing backend, model or size re-indexes memory in the background. */
    public function model(): string
    {
        if ($this->fake) return 'fake';
        $dims = $this->dims();
        return $this->cfg->embeddingsBackend() . ':' . $this->cfg->embeddingsModel . ($dims ? "@$dims" : '');
    }

    /** Vector size kept. Models trained for it (Qwen3, EmbeddingGemma) keep almost all quality at 512 and search twice as fast. */
    public function dims(): int
    {
        if ($this->cfg->embeddingsDims >= 0) return $this->cfg->embeddingsDims; // 0 = full size
        return preg_match('/qwen3-embedding|embeddinggemma|text-embedding-3|gemini-embedding/i', $this->cfg->embeddingsModel) ? 512 : 0;
    }

    /** Some models expect an instruction or prefix, different for queries and documents. */
    public static function prepare(string $model, string $text, string $type): string
    {
        $m = strtolower($model);
        return match (true) {
            str_contains($m, 'qwen3-embedding') => $type === 'query'
                ? "Instruct: Given a message from the user, retrieve past conversations, notes and documents that help answer it\nQuery:$text" : $text,
            str_contains($m, 'embeddinggemma') => $type === 'query' ? "task: search result | query: $text" : "title: none | text: $text",
            str_contains($m, 'nomic') => ($type === 'query' ? 'search_query: ' : 'search_document: ') . $text,
            str_contains($m, '-e5-') || str_starts_with($m, 'e5-') => ($type === 'query' ? 'query: ' : 'passage: ') . $text,
            str_contains($m, 'mxbai-embed') => $type === 'query' ? "Represent this sentence for searching relevant passages: $text" : $text,
            default => $text,
        };
    }

    /**
     * @param string[] $texts
     * @param string $type "document" or "query"
     * @return array<int,string> packed, L2-normalised float32 vectors
     */
    public function embed(array $texts, string $type): array
    {
        return Privacy::as('embeddings', fn() => $this->embedNow($texts, $type));
    }

    private function embedNow(array $texts, string $type): array
    {
        $texts = array_map(fn($t) => mb_substr($t, 0, 6000), $texts);
        $vecs = $this->fake ? ($this->fake)($texts, $type) : match ($this->cfg->embeddings) {
            'ollama' => $this->ollama($texts, $type),
            'openai' => $this->openai($texts, 'https://api.openai.com/v1', $this->cfg->openaiKey),
            'gemini' => $this->openai($texts, $this->cfg->geminiBaseUrl . '/openai', $this->cfg->geminiKey), // OpenAI-compatible endpoint
            'voyage' => $this->voyage($texts, $type),
            'sovereign' => $this->openai($texts, $this->cfg->sovereignUrl, $this->cfg->sovereignKey),
            default => throw new \RuntimeException('embeddings are off'),
        };
        $dims = $this->fake ? 0 : $this->dims();
        return array_map(fn($v) => self::pack($dims > 0 && count($v) > $dims ? array_slice($v, 0, $dims) : $v), $vecs);
    }

    public static function pack(array $v): string
    {
        $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0; // re-normalised, also after truncation
        return pack('g*', ...array_map(fn($x) => $x / $n, $v));
    }

    public static function dot(string $a, string $b): float
    {
        $x = unpack('g*', $a); $y = unpack('g*', $b);
        $s = 0.0;
        foreach ($x as $i => $v) $s += $v * ($y[$i] ?? 0.0);
        return $s;
    }

    private function ollama(array $texts, string $type): array
    {
        $model = $this->cfg->embeddingsModel;
        $r = Http::json($this->cfg->ollamaUrl . '/api/embed', [
            'model' => $model, 'input' => array_map(fn($t) => self::prepare($model, $t, $type), $texts),
            'keep_alive' => '1h', // stay loaded: the first call after a pause would otherwise reload the model
        ], [], 300);
        return $r['embeddings'] ?? throw new \RuntimeException('ollama: no embeddings in response' . (isset($r['error']) ? ": {$r['error']}" : ''));
    }

    private function openai(array $texts, string $base, string $key): array
    {
        $r = Http::json("$base/embeddings", ['model' => $this->cfg->embeddingsModel, 'input' => $texts],
            $key !== '' ? ['Authorization: Bearer ' . $key] : [], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }

    private function voyage(array $texts, string $type): array
    {
        $r = Http::json('https://api.voyageai.com/v1/embeddings', ['model' => $this->cfg->embeddingsModel, 'input' => $texts, 'input_type' => $type],
            ['Authorization: Bearer ' . $this->cfg->voyageKey], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }
}
