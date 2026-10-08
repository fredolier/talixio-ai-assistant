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
    public function model(): string { return $this->fake ? 'fake' : $this->cfg->embeddingsBackend() . ':' . $this->cfg->embeddingsModel; }

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
            'openai' => $this->openai($texts, 'https://api.openai.com/v1'),
            'gemini' => $this->openai($texts, $this->cfg->openaiBaseUrl), // OpenAI-compatible endpoint
            'voyage' => $this->voyage($texts, $type),
            default => throw new \RuntimeException('embeddings are off'),
        };
        return array_map([self::class, 'pack'], $vecs);
    }

    public static function pack(array $v): string
    {
        $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
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
        $prefix = str_contains($this->cfg->embeddingsModel, 'nomic') ? ($type === 'query' ? 'search_query: ' : 'search_document: ') : '';
        $r = Http::json($this->cfg->ollamaUrl . '/api/embed', ['model' => $this->cfg->embeddingsModel, 'input' => array_map(fn($t) => $prefix . $t, $texts)], [], 120);
        return $r['embeddings'] ?? throw new \RuntimeException('ollama: no embeddings in response');
    }

    private function openai(array $texts, string $base): array
    {
        $r = Http::json("$base/embeddings", ['model' => $this->cfg->embeddingsModel, 'input' => $texts],
            ['Authorization: Bearer ' . $this->cfg->openaiKey], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }

    private function voyage(array $texts, string $type): array
    {
        $r = Http::json('https://api.voyageai.com/v1/embeddings', ['model' => $this->cfg->embeddingsModel, 'input' => $texts, 'input_type' => $type],
            ['Authorization: Bearer ' . $this->cfg->voyageKey], 60);
        return array_column($r['data'] ?? [], 'embedding');
    }
}
