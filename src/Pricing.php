<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Estimated cost per call in USD. Prices are per million tokens and matched by the first pattern
 * contained in the model name. Defaults are list prices as known when this was written: check
 * your provider's pricing page and override with TALIXIO_PRICES="pattern=in/out,...".
 */
final class Pricing
{
    public const DEFAULTS = [
        'haiku' => [1.0, 5.0], 'sonnet' => [3.0, 15.0], 'opus' => [5.0, 25.0],
        // Gemini 2.5 from Google's pricing page; newer Gemini models from third-party listings (Oct 2026), check them
        'gemini-2.5-flash-lite' => [0.1, 0.4], 'gemini-2.5-flash' => [0.3, 2.5], 'gemini-2.5-pro' => [1.25, 10.0],
        'flash-lite' => [0.25, 1.5], 'gemini-flash' => [0.75, 3.75], 'flash' => [0.75, 3.75], 'gemini' => [2.0, 12.0],
        'gpt-4.1-nano' => [0.1, 0.4], 'gpt-4.1-mini' => [0.4, 1.6], 'gpt-4.1' => [2.0, 8.0],
        'gpt-4o-mini' => [0.15, 0.6], 'gpt-4o' => [2.5, 10.0],
    ];

    /** @var array<string,array{0:float,1:float}> */
    private array $prices;

    public function __construct(string $overrides = '', private bool $localModels = false)
    {
        $custom = [];
        foreach (array_filter(array_map('trim', explode(',', $overrides))) as $item) {
            if (preg_match('#^([^=]+)=([\d.]+)/([\d.]+)$#', $item, $m)) $custom[strtolower(trim($m[1]))] = [(float)$m[2], (float)$m[3]];
        }
        $this->prices = $custom + self::DEFAULTS; // custom patterns are checked first
    }

    /** @return array{0:float,1:float}|null */
    public function priceFor(string $model): ?array
    {
        $m = strtolower($model);
        foreach ($this->prices as $pattern => $p) if (str_contains($m, $pattern)) return $p;
        return $this->localModels ? [0.0, 0.0] : null;
    }

    /** Anthropic cache: reads cost 10% of input, writes 125%. */
    public function cost(array $u): ?float
    {
        if (isset($u['cost']) && $u['cost'] !== null) return (float)$u['cost'];
        $p = $this->priceFor($u['model']);
        if ($p === null) return null;
        return ($u['in'] * $p[0] + ($u['cache_read'] ?? 0) * $p[0] * 0.1 + ($u['cache_write'] ?? 0) * $p[0] * 1.25 + $u['out'] * $p[1]) / 1e6;
    }
}
