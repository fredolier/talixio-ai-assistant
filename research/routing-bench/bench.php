<?php
// Routing benchmark: replays synthetic cases through labelling strategies and scores them.
// Usage: php bench.php [rules|router|router-split|all] [cases.jsonl]
// Router strategies call an OpenAI-compatible endpoint: BENCH_URL (…/v1), BENCH_MODEL, BENCH_KEY.

const LEVELS = ['public', 'business', 'confidential', 'secret'];
const TIERS = ['fast', 'std', 'deep'];

function rank(string $l): int { $i = array_search($l, LEVELS, true); return $i === false ? 0 : $i; }
function top(array $ls): string { return LEVELS[max(array_map('rank', $ls ?: ['public']))]; }

// Baseline: space floor plus pattern rules. No model involved.
function rules(array $c): array
{
    $t = $c['text'] . "\n" . ($c['attach'] ?? '');
    $l = [$c['space']];
    if (preg_match('/\b(password|mot de passe|recovery code|api key|sk-[a-z0-9-]{8,})\b.*\S|\bsk-[A-Za-z0-9-]{8,}/i', $t) && preg_match('/\b(is|:|est)\s*\S{6,}|\bsk-[A-Za-z0-9-]{8,}/i', $t)) $l[] = 'secret';
    if (preg_match('/\b(medical|médical|diagnos|patent|brevet)/i', $t)) $l[] = 'secret';
    if (preg_match('/\bIBAN\b|\bFR\d{2}( ?\d{4}){5}|salary|salaire|offer letter|job offer|lettre d.offre|pay rise|augmentation|bonus|sick leave|burn-out|warning letter|lay off|licenci|acquisition|divorce|strictly confidential/i', $t)) $l[] = 'confidential';
    if (preg_match('/\binvoice\b|facture|internal memo|not yet announced|@?\b[a-z0-9-]+\.example\b/i', $t)) $l[] = 'business';
    $n = str_word_count($t);
    $tier = preg_match('/\b(compare|plan|prove|analy[sz]e|recommend|every|roadmap)\b/i', $t) || $n > 60 ? 'deep' : ($n < 18 ? 'fast' : 'std');
    return ['label' => top($l), 'tier' => $tier, 'steps' => null];
}

function router(array $c, bool $split): array
{
    $url = rtrim(getenv('BENCH_URL') ?: '', '/');
    if ($url === '' || !getenv('BENCH_MODEL')) { fwrite(STDERR, "Set BENCH_URL, BENCH_MODEL and BENCH_KEY to run the router.\n"); exit(2); }
    $sys = "You label requests for a business assistant. Levels, lowest to highest: public (nothing private), business (customers, internal plans, invoices), confidential (salaries, HR, health of staff, bank details, M&A, legal disputes), secret (credentials, named medical records, patents, trade secrets). Tiers: fast (short, simple), std (normal), deep (long reasoning, planning, big documents).\n"
        . ($split ? "If the request mixes parts that need different levels, split it into steps; quote each step's own words exactly in 'text'. Otherwise return one step.\n" : '')
        . 'Answer only JSON: {"label":"…","tier":"…"' . ($split ? ',"steps":[{"text":"…","label":"…"}]' : '') . '}';
    $user = $c['text'] . (isset($c['attach']) ? "\n\n[Attachment]\n" . $c['attach'] : '');
    $t0 = microtime(true);
    $ch = curl_init($url . '/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . getenv('BENCH_KEY')],
        CURLOPT_POSTFIELDS => json_encode(['model' => getenv('BENCH_MODEL'), 'temperature' => 0, 'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]])]);
    $raw = curl_exec($ch);
    static $warned = false;
    if (!$warned && (curl_errno($ch) || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400)) { $warned = true; fwrite(STDERR, 'router error: ' . (curl_error($ch) ?: substr((string) $raw, 0, 300)) . "\n"); }
    $ms = (int) ((microtime(true) - $t0) * 1000);
    $r = json_decode((string) $raw, true);
    $txt = $r['choices'][0]['message']['content'] ?? '';
    $j = json_decode(preg_replace('/^```(json)?|```$/m', '', trim($txt)), true) ?: [];
    $tok = ($r['usage']['prompt_tokens'] ?? 0) + ($r['usage']['completion_tokens'] ?? 0);
    // The router may only raise the space's level, never lower it.
    $steps = $split && !empty($j['steps']) ? array_map(fn($s) => ['text' => (string) ($s['text'] ?? ''), 'label' => top([$c['space'], (string) ($s['label'] ?? 'secret')])], $j['steps']) : null;
    return ['label' => top([$c['space'], in_array($j['label'] ?? '', LEVELS, true) ? $j['label'] : 'secret']),
        'tier' => in_array($j['tier'] ?? '', TIERS, true) ? $j['tier'] : 'std', 'steps' => $steps, 'ms' => $ms, 'tokens' => $tok, 'bad' => !$j];
}

function score(string $name, array $cases, callable $f): array
{
    $m = ['leak' => 0, 'over' => 0, 'exact' => 0, 'tier' => 0, 'stepLeak' => 0, 'splitCases' => 0, 'splitOk' => 0, 'bad' => 0, 'ms' => [], 'tokens' => 0];
    $fails = [];
    foreach ($cases as $c) {
        $p = $f($c);
        $d = rank($p['label']) - rank($c['label']);
        if ($d < 0) { $m['leak']++; $fails[] = "LEAK {$c['id']}: {$p['label']} < {$c['label']}"; }
        elseif ($d > 0) { $m['over']++; $fails[] = "over {$c['id']}: {$p['label']} > {$c['label']}"; }
        else $m['exact']++;
        if ($p['tier'] === $c['tier']) $m['tier']++;
        if (!empty($p['bad'])) $m['bad']++;
        if (isset($p['ms'])) $m['ms'][] = $p['ms'];
        $m['tokens'] += $p['tokens'] ?? 0;
        if (!empty($c['split'])) {
            $m['splitCases']++;
            if ($p['steps'] !== null && count($p['steps']) >= 2) {
                $ok = true;
                foreach ($c['split'] as $g) {
                    // A gold part's marker must sit in a step labelled at least as high; a lower one is a leak.
                    foreach ($p['steps'] as $s) if (stripos($s['text'], $g['marker']) !== false && rank($s['label']) < rank($g['label'])) { $m['stepLeak']++; $ok = false; $fails[] = "STEP LEAK {$c['id']}: '{$g['marker']}' sent as {$s['label']}"; }
                }
                if ($ok) $m['splitOk']++;
            }
        }
    }
    $n = count($cases);
    sort($m['ms']);
    $pct = fn($q) => $m['ms'] ? $m['ms'][(int) floor(($q / 100) * (count($m['ms']) - 1))] . ' ms' : '-';
    printf("\n== %s (%d cases)\n", $name, $n);
    printf("  leaks (label too low)  %d   <- must be 0\n  step leaks             %d   <- must be 0\n", $m['leak'], $m['stepLeak']);
    printf("  label exact            %d%%\n  label too high         %d%%\n  tier right             %d%%\n", 100 * $m['exact'] / $n, 100 * $m['over'] / $n, 100 * $m['tier'] / $n);
    printf("  split cases handled    %d / %d\n  unreadable answers     %d\n  latency p50 / p95      %s / %s\n  tokens                 %d\n", $m['splitOk'], $m['splitCases'], $m['bad'], $pct(50), $pct(95), $m['tokens']);
    foreach ($fails as $x) echo "    $x\n";
    return $m;
}

$mode = $argv[1] ?? 'rules';
$file = $argv[2] ?? __DIR__ . '/cases.jsonl';
$cases = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
if (in_array($mode, ['rules', 'all'], true)) score('rules only (router off)', $cases, 'rules');
if (in_array($mode, ['router', 'all'], true)) score('router, splitter off · ' . getenv('BENCH_MODEL'), $cases, fn($c) => router($c, false));
if (in_array($mode, ['router-split', 'all'], true)) score('router + splitter · ' . getenv('BENCH_MODEL'), $cases, fn($c) => router($c, true));
