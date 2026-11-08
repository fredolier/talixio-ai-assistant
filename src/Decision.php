<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Typed decisions with probabilities from a "System One" model (TypeSafe's Jev, or an open one that speaks the
 * same POST /v1/systemone: Kev, Rizzo Flow…). The model answers choice / score / yes-no questions about a state;
 * it does not write text. The address is set in Settings → Model and budget → Forecast and decisions.
 */
final class Decision
{
    public const TYPES = ['choice', 'score', 'noul'];
    /** Tests replace the HTTP call: fn(string $url, array $payload, array $headers): array{0:int,1:string}. */
    public static ?\Closure $post = null;

    /**
     * @param array<string,array{type:string,instructions?:string,criteria?:mixed}> $questions keyed by an id of the caller's choosing
     * @return array<string,array<string,mixed>> the model's answers, keyed the same
     */
    public static function run(Config $cfg, string $state, array $questions): array
    {
        if ($cfg->decisionUrl === '') throw new \RuntimeException('no decision model is set (Settings → Model and budget → Forecast and decisions)');
        $q = self::questions($questions);
        $payload = ['state' => mb_substr($state, 0, 60000), 'questions' => $q] + ($cfg->decisionModel !== '' ? ['model' => $cfg->decisionModel] : []);
        $headers = ['Content-Type: application/json'] + ($cfg->decisionKey !== '' ? [1 => 'Authorization: Bearer ' . $cfg->decisionKey] : []);
        $url = $cfg->decisionUrl . '/v1/systemone';
        [$code, $body] = self::$post ? (self::$post)($url, $payload, $headers) : Privacy::as('decision', fn() => Http::request('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE), 90));
        $d = json_decode($body, true);
        if ($code >= 400 || !is_array($d)) throw new \RuntimeException("the decision model answered HTTP $code" . (is_array($d) && is_string($d['error'] ?? null) ? ': ' . mb_substr($d['error'], 0, 200) : (is_array($d) && isset($d['detail']) ? ': ' . mb_substr(json_encode($d['detail']), 0, 200) : '')));
        $answers = is_array($d['answers'] ?? null) ? $d['answers'] : [];
        foreach (array_keys($q) as $id) if (!isset($answers[$id]) || !is_array($answers[$id])) throw new \RuntimeException("the decision model gave no answer for \"$id\"");
        return array_intersect_key($answers, $q);
    }

    /** Validate and normalise the questions (types, 1–26 options, levels). */
    public static function questions(array $questions): array
    {
        if (!$questions || count($questions) > 20) throw new \InvalidArgumentException('between 1 and 20 questions');
        $out = [];
        foreach ($questions as $id => $q) {
            $id = (string)$id;
            if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $id)) throw new \InvalidArgumentException("bad question id \"$id\"");
            $type = (string)($q['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException("question \"$id\": type must be choice, score or noul (yes/no)");
            $o = ['type' => $type] + (($ins = trim((string)($q['instructions'] ?? ''))) !== '' ? ['instructions' => mb_substr($ins, 0, 2000)] : []);
            $c = $q['criteria'] ?? null;
            if ($type === 'choice') {
                if (is_array($c) && array_is_list($c)) $c = array_fill_keys(array_map('strval', $c), null);
                if (!is_array($c) || count($c) < 2 || count($c) > 26) throw new \InvalidArgumentException("question \"$id\": a choice needs 2 to 26 options");
                $o['criteria'] = array_map(fn($v) => $v === null || $v === '' ? null : mb_substr((string)$v, 0, 300), $c);
            } elseif ($type === 'score') {
                if (!is_array($c) || !array_is_list($c) || count($c) < 2 || count($c) > 10) throw new \InvalidArgumentException("question \"$id\": a score needs 2 to 10 ordered levels, lowest first");
                $o['criteria'] = array_map(fn($v) => mb_substr((string)$v, 0, 200), $c);
            } elseif (is_array($c) && $c) {
                $o['criteria'] = array_intersect_key(array_map(fn($v) => mb_substr((string)$v, 0, 300), $c), ['true' => 1, 'false' => 1]);
            }
            $out[$id] = $o;
        }
        return $out;
    }

    public static function render(array $answers): string
    {
        $p = fn($x) => round(((float)$x) * 100) . '%';
        $lines = [];
        foreach ($answers as $id => $a) {
            $type = (string)($a['type'] ?? '');
            if ($type === 'choice') {
                $pr = (array)($a['probabilities'] ?? []); arsort($pr);
                $lines[] = "- **$id** → " . ($a['choice'] ?? '?') . ' (confidence ' . $p($a['confidence'] ?? 0) . ')' . ($pr ? '; ' . implode(', ', array_map(fn($k, $v) => "$k " . $p($v), array_keys($pr), $pr)) : '');
            } elseif ($type === 'score') {
                $leg = (array)($a['legend'] ?? []); $s = (float)($a['score'] ?? 0);
                $lines[] = "- **$id** → score " . round($s, 2) . ($leg ? ' (level ' . ($leg[(string)(int)round($s)] ?? '?') . ')' : '') . ', confidence ' . $p($a['confidence'] ?? 0);
            } else {
                $lines[] = "- **$id** → yes with probability " . $p($a['noul'] ?? 0);
            }
        }
        return "Decision model answers (probabilities come from the model, not from a language model's guess):\n" . implode("\n", $lines);
    }
}
