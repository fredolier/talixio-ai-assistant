<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Time-series forecasts. With a TimesFM service configured (deploy/docker/models.Dockerfile, TALIXIO_FORECAST_URL)
 * the forecast comes from Google's TimesFM 2.5; without one a simple built-in method answers (seasonal naive with
 * drift, or damped trend), clearly labelled as such — enough for a first look, never presented as TimesFM.
 */
final class Forecast
{
    public const MAX_POINTS = 5000;
    public const MAX_HORIZON = 256;
    /** Tests replace the HTTP call: fn(string $url, array $payload, array $headers): array{0:int,1:string}. */
    public static ?\Closure $post = null;

    /** A list of numbers, or text with numbers separated by commas, semicolons, spaces or lines. */
    public static function parseSeries(mixed $in): array
    {
        if (is_string($in)) { // "1,2,3" (commas) or "1,5 2,5 3" / one per line (decimal commas allowed)
            $t = trim($in);
            $in = preg_match('/[;\s]/', $t) ? preg_split('/[\s;]+/', $t, -1, PREG_SPLIT_NO_EMPTY) : explode(',', $t);
        }
        $out = [];
        foreach ((array)$in as $v) {
            if (is_string($v)) $v = str_replace([' ', ','], ['', '.'], $v);
            if (!is_numeric($v)) throw new \InvalidArgumentException('the series must contain only numbers (found "' . mb_substr((string)$v, 0, 20) . '")');
            $out[] = (float)$v;
        }
        if (count($out) < 3) throw new \InvalidArgumentException('at least 3 values are needed to forecast');
        if (count($out) > self::MAX_POINTS) $out = array_slice($out, -self::MAX_POINTS);
        return $out;
    }

    /** @return array{method:string,horizon:int,mean:float[],p10:float[],p90:float[],note:string} */
    public static function run(Config $cfg, array $values, int $horizon, ?int $season = null): array
    {
        $horizon = max(1, min(self::MAX_HORIZON, $horizon));
        if ($cfg->forecastUrl !== '') {
            try { return self::remote($cfg, $values, $horizon); }
            catch (\Throwable $e) { $r = self::baseline($values, $horizon, $season); $r['note'] = 'The TimesFM service did not answer (' . $e->getMessage() . '): this is the built-in simple method instead.'; return $r; }
        }
        return self::baseline($values, $horizon, $season);
    }

    private static function remote(Config $cfg, array $values, int $horizon): array
    {
        $payload = ['series' => $values, 'horizon' => $horizon, 'model' => $cfg->forecastModel];
        $url = $cfg->forecastUrl . '/forecast';
        [$code, $body] = self::$post ? (self::$post)($url, $payload, []) : Privacy::as('forecast', fn() => Http::request('POST', $url, ['Content-Type: application/json'], json_encode($payload), 120));
        $d = json_decode($body, true);
        if ($code >= 400 || !is_array($d)) throw new \RuntimeException("HTTP $code" . (is_array($d) && isset($d['error']) ? ': ' . $d['error'] : ''));
        $mean = array_map('floatval', (array)($d['mean'] ?? []));
        if (count($mean) !== $horizon) throw new \RuntimeException('unexpected answer');
        return ['method' => (string)($d['model'] ?? 'TimesFM'), 'horizon' => $horizon, 'mean' => $mean,
            'p10' => array_map('floatval', (array)($d['p10'] ?? $mean)), 'p90' => array_map('floatval', (array)($d['p90'] ?? $mean)), 'note' => ''];
    }

    /** Seasonal naive with drift when a season fits twice in the series, else a damped linear trend. */
    public static function baseline(array $y, int $h, ?int $m = null): array
    {
        $n = count($y); $mean = []; $resid = [];
        if ($m !== null && $m >= 2 && $n >= 2 * $m) {
            $drift = (array_sum(array_slice($y, -$m)) - array_sum(array_slice($y, -2 * $m, $m))) / ($m * $m); // per step
            for ($i = $m; $i < $n; $i++) $resid[] = $y[$i] - $y[$i - $m];
            for ($k = 1; $k <= $h; $k++) $mean[] = $y[$n - $m + (($k - 1) % $m)] + $drift * (intdiv($k - 1, $m) + 1) * $m;
            $method = "built-in: seasonal naive with drift (season $m)";
        } else {
            $a = 0.5; $b = 0.3; $phi = 0.9; $lvl = $y[0]; $tr = $y[1] - $y[0];
            for ($i = 1; $i < $n; $i++) {
                $pred = $lvl + $phi * $tr; $resid[] = $y[$i] - $pred;
                $new = $a * $y[$i] + (1 - $a) * $pred; $tr = $b * ($new - $lvl) + (1 - $b) * $phi * $tr; $lvl = $new;
            }
            for ($k = 1; $k <= $h; $k++) { $s = 0.0; for ($j = 1; $j <= $k; $j++) $s += $phi ** $j; $mean[] = $lvl + $s * $tr; }
            $method = 'built-in: damped linear trend';
        }
        $sd = 0.0;
        if ($resid) { $mu = array_sum($resid) / count($resid); $sd = sqrt(array_sum(array_map(fn($r) => ($r - $mu) ** 2, $resid)) / max(1, count($resid) - 1)); }
        $p10 = $p90 = [];
        foreach ($mean as $i => $v) { $w = 1.2816 * $sd * sqrt($i + 1); $p10[] = $v - $w; $p90[] = $v + $w; }
        return ['method' => $method, 'horizon' => $h, 'mean' => $mean, 'p10' => $p10, 'p90' => $p90,
            'note' => 'A simple built-in method, not a foundation model: install the TimesFM service (Settings → Model and budget → Forecast and decisions) for better forecasts.'];
    }

    public static function render(array $r, array $history): string
    {
        $f = fn(float $x) => rtrim(rtrim(number_format($x, abs($x) >= 100 ? 1 : 3, '.', ''), '0'), '.') ?: '0';
        $lines = ["Forecast ({$r['method']}) over {$r['horizon']} steps, from " . count($history) . ' points (last: ' . $f(end($history)) . '). p10–p90 is an 80% interval.', '', '| step | forecast | p10 | p90 |', '|---|---|---|---|'];
        foreach ($r['mean'] as $i => $v) $lines[] = '| +' . ($i + 1) . ' | ' . $f($v) . ' | ' . $f($r['p10'][$i] ?? $v) . ' | ' . $f($r['p90'][$i] ?? $v) . ' |';
        if ($r['note'] !== '') $lines[] = "\n_" . $r['note'] . '_';
        return implode("\n", $lines);
    }
}
