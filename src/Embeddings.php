<?php
declare(strict_types=1);
namespace Talixio;

/** Text embeddings for semantic memory search. Backends: ollama (local), openai, voyage. */
final class Embeddings
{
    /** @var callable|null test hook: fn(array $texts, string $type): array<array<float>> */
    public $fake = null;

    public function __construct(private Config $cfg) {}

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
        $texts = array_map(fn($t) => mb_substr($t, 0, 6000), $texts);
        $vecs = $this->fake ? ($this->fake)($texts, $type) : match ($this->cfg->embeddings) {
            'ollama' => $this->ollama($texts, $type),
            'openai' => $this->openai($texts, 'https://api.openai.com/v1', $this->cfg->openaiKey),
            'gemini' => $this->openai($texts, $this->cfg->geminiBaseUrl . '/openai', $this->cfg->geminiKey), // OpenAI-compatible endpoint
            'voyage' => $this->voyage($texts, $type),
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
            ['Authorization: Bearer ' . $key], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }

    private function voyage(array $texts, string $type): array
    {
        $r = Http::json('https://api.voyageai.com/v1/embeddings', ['model' => $this->cfg->embeddingsModel, 'input' => $texts, 'input_type' => $type],
            ['Authorization: Bearer ' . $this->cfg->voyageKey], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }
}
