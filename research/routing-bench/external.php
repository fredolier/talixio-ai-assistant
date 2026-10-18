<?php
// Benchmark on outside cases (run fetch.php first). The trained classifier is tuned by 5-fold cross-validation
// on the dev split only; every strategy is then scored once on the locked test split.
// Usage: php external.php [router [max cases]]   (router needs BENCH_URL, BENCH_MODEL, BENCH_KEY)

require __DIR__ . '/bench.php';

$load = fn($n) => array_map(fn($l) => json_decode($l, true), file(__DIR__ . "/data/$n.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$dev = $load('dev');
$test = $load('test');

// Words plus the shape of anything holding a digit (e.g. 'FR76 3000 6000' -> 'AADD DDDD DDDD' pieces).
function tokens(array $c): array
{
    $t = mb_strtolower($c['text'] . ' ' . ($c['attach'] ?? ''));
    preg_match_all('/[\p{L}\p{N}_@.\-]+/u', $t, $m);
    $out = [];
    foreach ($m[0] as $w) $out[] = preg_match('/\d/', $w) ? 'ʃ' . preg_replace(['/\d/', '/\p{L}/u'], ['D', 'A'], $w) : $w;
    return $out;
}

// Multinomial naive Bayes, Laplace smoothing.
function train(array $cases): array
{
    $m = ['prior' => array_fill_keys(LEVELS, 1), 'n' => array_fill_keys(LEVELS, 0), 'w' => [], 'v' => []];
    foreach ($cases as $c) {
        $m['prior'][$c['label']]++;
        foreach (tokens($c) as $w) { $m['w'][$c['label']][$w] = ($m['w'][$c['label']][$w] ?? 0) + 1; $m['n'][$c['label']]++; $m['v'][$w] = 1; }
    }
    return $m;
}

function probs(array $m, array $c): array
{
    $v = count($m['v']);
    $tot = array_sum($m['prior']);
    $lp = [];
    foreach (LEVELS as $l) {
        $s = log($m['prior'][$l] / $tot);
        foreach (tokens($c) as $w) if (isset($m['v'][$w])) $s += log((($m['w'][$l][$w] ?? 0) + 1) / ($m['n'][$l] + $v));
        $lp[$l] = $s;
    }
    $mx = max($lp);
    $e = array_map(fn($x) => exp($x - $mx), $lp);
    $z = array_sum($e);
    return array_map(fn($x) => $x / $z, $e);
}

// Leak-averse decision: the highest level L with P(level >= L) >= tau.
function decide(array $p, float $tau, string $space): string
{
    $acc = 0;
    foreach (array_reverse(LEVELS) as $l) { $acc += $p[$l]; if ($acc >= $tau) return top([$l, $space]); }
    return $space;
}

// 5-fold cross-validation on dev: pick the largest tau whose leak rate stays at or under 1 %.
usort($dev, fn($a, $b) => strcmp(md5($a['id']), md5($b['id'])));
$folds = array_chunk($dev, (int) ceil(count($dev) / 5));
$taus = [0.5, 0.4, 0.3, 0.2, 0.15, 0.1, 0.05, 0.02, 0.01, 0.005, 0.001];
$cv = array_fill_keys(array_map('strval', $taus), ['leak' => 0, 'over' => 0]);
foreach ($folds as $k => $hold) {
    $m = train(array_merge(...array_values(array_diff_key($folds, [$k => 1]))));
    foreach ($hold as $c) {
        $p = probs($m, $c);
        foreach ($taus as $t) { $d = rank(decide($p, $t, $c['space'])) - rank($c['label']); if ($d < 0) $cv[(string) $t]['leak']++; elseif ($d > 0) $cv[(string) $t]['over']++; }
    }
}
echo "\n== naive Bayes, 5-fold cross-validation on dev (" . count($dev) . " cases)\n   tau    leaks  too high\n";
$tau = end($taus);
foreach ($cv as $t => $r) {
    printf("   %-6s %4.1f%%  %4.1f%%\n", $t, 100 * $r['leak'] / count($dev), 100 * $r['over'] / count($dev));
    if ($tau === end($taus) && $r['leak'] / count($dev) <= 0.01) $tau = (float) $t;
}
echo "   chosen tau = $tau\n";

// Final, single pass on the locked test split.
$nb = train($dev);
$nbf = fn($c) => ['label' => decide(probs($nb, $c), $tau, $c['space']), 'tier' => '', 'steps' => null];
$bySrc = function (string $name, callable $f) use ($test) {
    $r = [];
    foreach ($test as $c) { $d = rank($f($c)['label']) - rank($c['label']); $r[$c['src']][$d < 0 ? 'leak' : ($d > 0 ? 'over' : 'ok')][] = 1; }
    echo "   by source:";
    foreach ($r as $s => $x) printf(" %s %d leaks / %d over / %d", $s, count($x['leak'] ?? []), count($x['over'] ?? []), array_sum(array_map('count', $x)));
    echo "\n";
};
$rulesf = fn($c) => rules($c);
$both = fn($c) => ['label' => top([rules($c)['label'], $nbf($c)['label']]), 'tier' => '', 'steps' => null];
$floor = fn($c) => ['label' => 'business', 'tier' => '', 'steps' => null];
foreach (['space floor raised to business, nothing else' => $floor, 'rules only (written before these cases)' => $rulesf,
          "naive Bayes, tau $tau" => $nbf, 'rules + naive Bayes, keep higher' => $both] as $n => $f) { score("TEST · $n", $test, $f); $bySrc($n, $f); }

if (($argv[1] ?? '') === 'router') {
    $sub = array_slice($test, 0, (int) ($argv[2] ?? count($test)));
    $cache = [];
    $rf = function ($c) use (&$cache) { return $cache[$c['id']] ??= router($c, false); };
    score('TEST · router · ' . getenv('BENCH_MODEL'), $sub, $rf);
    $bySrc('router', fn($c) => isset($cache[$c['id']]) ? $cache[$c['id']] : ['label' => $c['label']]);
    score('TEST · rules + router, keep higher', $sub, fn($c) => ['label' => top([rules($c)['label'], $rf($c)['label']]), 'tier' => '', 'steps' => null]);
}
