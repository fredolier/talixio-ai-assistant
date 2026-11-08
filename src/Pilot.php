<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The autonomous pilot's brain (roadmap: « towards a fleet that decides like you do »).
 *
 * The orchestrator relays; the assistant decides — within limits the USER sets and only the user can raise:
 *  - an autonomy mode per ladder level (L1..L5): `ask` (the assistant proposes, you answer), `tell` (it answers when
 *    confident, then tells you), `auto` (same, silently). L4/L5 (values, business), approvals, pushes, deploys,
 *    deletions, e-mails and credentials are never delegated, whatever the setting;
 *  - a confidence threshold, below which it only proposes;
 *  - an ordered list of priorities it arbitrates by.
 * Every answer it considers is a PREDICTION written down with a confidence. When you answer yourself (or confirm / correct
 * what it did) the prediction is scored: the accuracy per level feeds the suggestion to give it more room, a correction
 * lowers the level again at once, and each outcome is also a Mind observation (« how this person decides »).
 */
final class Pilot
{
    public const LEVELS = ['L1', 'L2', 'L3', 'L4', 'L5'];
    public const MODES = ['ask', 'tell', 'auto'];
    /** Levels the user keeps for good. */
    private const KEPT = ['L4', 'L5'];
    private const PRED_CAP = 200;
    private const WINDOW = 30;

    public function __construct(private App $app) {}

    private function file(): string { return $this->app->cfg->dataDir . '/pilot.json'; }

    public function state(): array
    {
        $s = is_file($f = $this->file()) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        $s['autonomy'] = array_merge(array_fill_keys(self::LEVELS, 'ask'), array_intersect_key((array)($s['autonomy'] ?? []), array_flip(self::LEVELS)));
        foreach (self::KEPT as $l) $s['autonomy'][$l] = 'ask';
        $s += ['enabled' => false, 'threshold' => 0.75, 'priorities' => [], 'rules' => [], 'daily_cap' => 20, 'predictions' => [], 'outcomes' => [], 'pending' => 0, 'last_wake' => 0];
        return $s;
    }

    private function mutate(callable $fn): mixed
    {
        $fh = @fopen($this->file() . '.lock', 'c');
        if ($fh) flock($fh, LOCK_EX);
        try {
            [$s, $ret] = $fn($this->state());
            file_put_contents($this->file(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            return $ret;
        } finally { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
    }

    /** What the cockpit shows: the policy, the accuracy per level, and what the assistant suggests you change. */
    public function overview(): array
    {
        $s = $this->state();
        $acc = [];
        foreach (self::LEVELS as $l) {
            $o = $s['outcomes'][$l] ?? [];
            $n = count($o);
            $acc[$l] = ['n' => $n, 'rate' => $n ? round(array_sum($o) / $n, 2) : null, 'mode' => $s['autonomy'][$l], 'kept' => in_array($l, self::KEPT, true),
                'suggest' => $this->suggest($l, $s)];
        }
        $recent = array_slice(array_reverse(array_values($s['predictions'])), 0, 30);
        return ['enabled' => (bool)$s['enabled'], 'threshold' => (float)$s['threshold'], 'priorities' => array_values($s['priorities']), 'rules' => array_values($s['rules']), 'daily_cap' => (int)$s['daily_cap'], 'sent_today' => $this->sentToday($s), 'levels' => $acc, 'recent' => $recent,
            'calibration' => $this->calibration($s)];
    }

    /** « ask » → « tell » after 10 outcomes at 90 %+; « tell » → « auto » after 20 at 95 %+. Only ever a suggestion. */
    private function suggest(string $l, array $s): ?string
    {
        if (in_array($l, self::KEPT, true)) return null;
        $o = $s['outcomes'][$l] ?? []; $n = count($o); if ($n === 0) return null;
        $r = array_sum($o) / $n;
        return match ($s['autonomy'][$l]) { 'ask' => $n >= 10 && $r >= 0.9 ? 'tell' : null, 'tell' => $n >= 20 && $r >= 0.95 ? 'auto' : null, default => null };
    }

    /** Average stated confidence vs real hit rate over the scored predictions: is the assistant over- or under-confident? */
    private function calibration(array $s): ?array
    {
        $c = []; $h = [];
        foreach ($s['predictions'] as $p) if (isset($p['hit'])) { $c[] = (float)$p['confidence']; $h[] = $p['hit'] ? 1 : 0; }
        return $c ? ['n' => count($c), 'confidence' => round(array_sum($c) / count($c), 2), 'hit_rate' => round(array_sum($h) / count($h), 2)] : null;
    }

    /** The user's settings (the only way to change them). Returns the new overview or an error string. */
    public function setPolicy(array $in): array|string
    {
        $err = null;
        $this->mutate(function (array $s) use ($in, &$err) {
            if (isset($in['enabled'])) $s['enabled'] = (bool)$in['enabled'];
            if (isset($in['threshold'])) $s['threshold'] = max(0.5, min(0.99, (float)$in['threshold']));
            foreach ((array)($in['autonomy'] ?? []) as $l => $m) {
                if (!in_array($l, self::LEVELS, true) || !in_array($m, self::MODES, true)) { $err = "unknown level or mode: $l"; continue; }
                if (in_array($l, self::KEPT, true) && $m !== 'ask') { $err = "$l is always yours to decide."; continue; }
                $s['autonomy'][$l] = $m;
            }
            if (isset($in['priorities'])) $s['priorities'] = self::cleanList($in['priorities']);
            if (isset($in['rules'])) $s['rules'] = self::cleanList($in['rules']);
            if (isset($in['daily_cap'])) $s['daily_cap'] = max(0, min(200, (int)$in['daily_cap']));
            return [$s, null];
        });
        $this->app->conductor()->refreshStatusFile();
        return $err ?? $this->overview();
    }

    /** Priorities as a clean ordered list (≤ 12 lines of ≤ 200 characters). */
    public static function cleanList(mixed $v): array
    {
        $a = is_array($v) ? $v : preg_split('/\R+/', (string)$v);
        $out = [];
        foreach ($a as $x) if (is_scalar($x) && ($x = mb_substr(trim(preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', (string)$x)), 0, 200)) !== '') $out[] = $x;
        return array_slice($out, 0, 12);
    }

    /** Answers the assistant sent by itself since midnight (the daily cap counts these). */
    private function sentToday(array $s): int
    {
        $t0 = strtotime('today');
        return count(array_filter($s['predictions'], fn($p) => ($p['action'] ?? '') === 'sent' && (int)($p['ts'] ?? 0) >= $t0));
    }

    public function rules(): array { return $this->state()['rules']; }

    public function priorities(): array { return $this->state()['priorities']; }

    // ---- the wake-up ----------------------------------------------------------------------------------------------

    /** A question just appeared: ask the worker to look at it soon (cheap; nothing runs here). */
    public function wake(): void
    {
        if (!$this->state()['enabled']) return;
        $this->mutate(function (array $s) { $s['pending'] = time(); return [$s, null]; });
    }

    /** Worker tick: run one triage turn when asked to (and, as a safety net, every 15 min for questions left unattended). */
    public function tick(int $now): void
    {
        $s = $this->state();
        if (!$s['enabled'] || !$this->app->conductor()->connected()) return;
        $open = array_filter($this->app->conductor()->waiting(), fn($a) => ($a['waiting']['kind'] ?? '') !== 'approval' && !isset($s['predictions'][$this->app->conductor()->waitingId($a)]));
        $due = ($s['pending'] > 0 && $now - $s['last_wake'] >= 20) || ($open && $now - $s['last_wake'] >= 900);
        if (!$due || !$open) { if ($s['pending'] > 0 && !$open) $this->mutate(function (array $s) { $s['pending'] = 0; return [$s, null]; }); return; }
        if ($this->app->budget()->status()['state'] !== 'ok') return;
        $this->mutate(function (array $s) use ($now) { $s['pending'] = 0; $s['last_wake'] = $now; return [$s, null]; });
        $out = $this->app->agent()->runTask('Fleet triage', self::triagePrompt(), 'standard', 0);
        $out = trim($out);
        if ($out !== '' && !str_starts_with($out, 'TRIAGE_OK')) $this->app->notifier()->notify('Fleet triage', mb_substr($out, 0, 1200));
    }

    public static function triagePrompt(): string
    {
        return "[Fleet triage — agents of the fleet wait for a decision.] Call fleet_inbox. For each waiting DECISION or INSTRUCTION question (never an approval): "
            . "weigh the similar past decisions it lists, what you know of how the user decides (your memory and Mind), the user's priorities and STANDING RULES (listed by fleet_inbox; a rule that covers the question beats a guess, and a question that breaks a rule is answered « no » or left to the user); then call fleet_decide with your answer, "
            . "an HONEST confidence from 0 to 1 (0.9+ only when a clear precedent or rule covers it; below 0.5 when you are guessing) and one sentence of rationale. "
            . "The code enforces the user's limits: you may always call it, it decides whether the answer is sent or only proposed. Do not answer levels L4/L5 or anything about pushes, deploys, deletions, e-mails or credentials: call fleet_decide with confidence 0.1 so the user sees your suggestion. "
            . "If several agents compete for the same effort, say which the priorities favour. Reply TRIAGE_OK if everything was handled quietly; otherwise ONE short message for the user (what needs them, with your suggestion).";
    }

    // ---- deciding and learning -------------------------------------------------------------------------------------

    /** Ladder level of a question: « L2 », « l2 », « level 2 » → L2; anything else is treated as L4 (kept by the user). */
    public static function levelOf(string $type): string
    {
        return preg_match('/\bL?([1-5])\b/i', $type, $m) ? 'L' . $m[1] : 'L4';
    }

    /**
     * fleet_decide: the assistant's answer to a waiting question, with a confidence. The user's limits are applied here,
     * not in the model: the answer is SENT only when the level allows it and the confidence reaches the threshold;
     * otherwise it is kept as a proposal the cockpit shows.
     */
    public function decide(string $agent, string $answer, float $confidence, string $rationale = ''): string
    {
        $answer = trim($answer);
        if ($answer === '') return 'error: no answer given.';
        $con = $this->app->conductor();
        $ag = null; foreach ($con->waiting() as $a) if ($a['id'] === $agent) $ag = $a;
        if (!$ag) return "error: agent `$agent` is not waiting for anything (read fleet_inbox again).";
        $w = $ag['waiting']; $id = $con->waitingId($ag);
        if ($w['kind'] === 'approval') return 'error: an approval is only the user\'s to give, in that session.';
        $level = self::levelOf((string)$w['type']);
        $confidence = max(0.0, min(1.0, $confidence));
        $s = $this->state();
        $mode = $s['autonomy'][$level];
        $sendable = $mode !== 'ask' && $confidence >= (float)$s['threshold'] && !self::sensitive($w['question'] . ' ' . $w['cost']) && $this->sentToday($s) < (int)$s['daily_cap'];
        $action = $sendable ? 'sent' : 'proposed';
        $this->mutate(function (array $s) use ($id, $agent, $ag, $w, $level, $answer, $confidence, $rationale, $action) {
            $s['predictions'][$id] = ['id' => $id, 'agent' => $agent, 'name' => $ag['name'], 'level' => $level, 'question' => mb_substr($w['question'], 0, 600), 'answer' => mb_substr($answer, 0, 1000),
                'confidence' => round($confidence, 2), 'rationale' => mb_substr(trim($rationale), 0, 300), 'action' => $action, 'ts' => time()];
            if (count($s['predictions']) > self::PRED_CAP) $s['predictions'] = array_slice($s['predictions'], -self::PRED_CAP, null, true);
            return [$s, null];
        });
        if ($sendable) {
            $r = $con->toFleet($answer, 0, $agent, $id);
            if (str_starts_with($r, 'error:')) return $r;
            if ($mode === 'tell') $this->app->notifier()->notify('Fleet: I answered for you', "{$ag['name']} asked: " . mb_substr($w['question'], 0, 300) . "\nI said: " . mb_substr($answer, 0, 300) . ' (' . (int)round($confidence * 100) . "% sure). Tell me if that was wrong — I will learn from it.");
            $con->refreshStatusFile();
            return "sent to `$agent` ($level, $mode, " . (int)round($confidence * 100) . '%). The user will confirm or correct it; do not repeat it.';
        }
        $why = ($mode !== 'ask' && $confidence >= (float)$s['threshold'] && $this->sentToday($s) >= (int)$s['daily_cap']) ? 'the daily cap of ' . (int)$s['daily_cap'] . ' answers sent by itself is reached' : (in_array($level, self::KEPT, true) ? "$level is the user's to decide" : ($mode === 'ask' ? "autonomy at $level is « ask »" : 'confidence below the threshold ' . (int)round($s['threshold'] * 100) . '%'));
        $this->app->conductor()->suggest($agent, $id, $answer, $confidence);
        $con->refreshStatusFile();
        return "not sent ($why): kept as a proposal the user sees in the cockpit with your answer and confidence.";
    }

    /** Anything touching what the user keeps for good is never sent by the assistant, whatever the level says. */
    public static function sensitive(string $text): bool
    {
        return (bool)preg_match('/\b(push|force.?push|deploy|déploi|production|prod\b|delete|supprim|drop table|rm -rf|credential|secret|password|mot de passe|token|api key|clé api|e-?mail|mail to|envoyer un mail|payment|paiement|invoice|facture|sign|signer)/iu', $text);
    }

    /** The user answered a question the assistant had a prediction for: score it. */
    public function learnAnswer(string $decisionId, string $answer): void
    {
        $this->score($decisionId, null, $answer, 'user');
    }

    /** The user confirms or corrects what the assistant sent (or proposed). A miss lowers that level at once. */
    public function verdict(string $decisionId, bool $ok, string $note = ''): array|string
    {
        $s = $this->state();
        if (!isset($s['predictions'][$decisionId])) return 'unknown decision';
        $this->score($decisionId, $ok, $note, 'verdict');
        return $this->overview();
    }

    private function score(string $id, ?bool $ok, string $actual, string $via): void
    {
        $learn = null;
        $this->mutate(function (array $s) use ($id, $ok, $actual, $via, &$learn) {
            $p = $s['predictions'][$id] ?? null;
            if (!$p || isset($p['hit'])) return [$s, null];
            $hit = $ok ?? self::same($p['answer'], $actual);
            $p['hit'] = $hit; $p['actual'] = mb_substr(trim($actual), 0, 600); $p['scored'] = time();
            $s['predictions'][$id] = $p;
            $l = $p['level'];
            $o = $s['outcomes'][$l] ?? []; $o[] = $hit ? 1 : 0;
            $s['outcomes'][$l] = array_slice($o, -self::WINDOW);
            $demoted = false;
            if (!$hit && $p['action'] === 'sent' && $s['autonomy'][$l] !== 'ask') { $s['autonomy'][$l] = $s['autonomy'][$l] === 'auto' ? 'tell' : 'ask'; $demoted = true; }
            $r = array_slice($s['outcomes'][$l], -10);
            if (count($r) >= 5 && array_sum($r) / count($r) < 0.7 && $s['autonomy'][$l] !== 'ask') { $s['autonomy'][$l] = 'ask'; $demoted = true; }
            $learn = [$p, $hit, $demoted, $s['autonomy'][$l]];
            return [$s, null];
        });
        if (!$learn) return;
        $this->app->conductor()->refreshStatusFile();
        [$p, $hit, $demoted, $mode] = $learn;
        try {
            if ($this->app->mind()->enabled()) {
                $q = mb_substr(preg_replace('/\s+/u', ' ', $p['question']), 0, 160);
                $this->app->mind()->addObservation('pattern', $hit
                    ? "On a fleet question ({$p['level']}: « $q »), the user chose or accepted: « " . mb_substr($p['answer'], 0, 120) . ' ».'
                    : "On a fleet question ({$p['level']}: « $q »), the assistant guessed « " . mb_substr($p['answer'], 0, 100) . " » but the user wanted: « " . mb_substr($actual, 0, 140) . ' »' . ($via === 'verdict' ? ' (a correction)' : '') . '.',
                    $hit ? 1 : 3, 0, 'fleet');
            }
        } catch (\Throwable $e) { Log::warn("pilot learning: {$e->getMessage()}"); }
        if ($demoted) $this->app->notifier()->notify('Fleet: autonomy lowered', "{$p['level']} goes back to « $mode » after a miss — I will propose and wait for you until my answers prove right again.");
    }

    /** Do two answers mean the same? Option text equal, one inside the other, or very close. */
    public static function same(string $a, string $b): bool
    {
        $n = fn(string $x) => trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($x)));
        [$a, $b] = [$n($a), $n($b)];
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        if (min(mb_strlen($a), mb_strlen($b)) >= 3 && (str_contains($a, $b) || str_contains($b, $a))) return true;
        similar_text($a, $b, $pct);
        return $pct >= 80;
    }

    /** Past decisions that look like this question: from the scored predictions and the answered-questions journal. */
    public function precedents(string $question, int $n = 3): array
    {
        $tok = fn(string $x) => array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($x)) ?: [], fn($w) => mb_strlen($w) > 3));
        $q = $tok($question); if (!$q) return [];
        $cands = [];
        foreach ($this->app->conductor()->decisions() as $d) $cands[] = ['q' => (string)$d['question'], 'a' => (string)$d['answer'], 'by' => $d['by'], 'ts' => $d['ts'], 'level' => $d['type'] ?? ''];
        $out = [];
        foreach ($cands as $c) {
            $t = $tok($c['q']); if (!$t) continue;
            $sim = count(array_intersect($q, $t)) / max(1, count(array_unique(array_merge($q, $t))));
            if ($sim >= 0.2) $out[] = $c + ['sim' => round($sim, 2)];
        }
        usort($out, fn($x, $y) => $y['sim'] <=> $x['sim']);
        return array_slice($out, 0, $n);
    }

    /** The proposal waiting for this decision, if any (the cockpit shows it as a suggested answer). */
    public function proposal(string $decisionId): ?array
    {
        $p = $this->state()['predictions'][$decisionId] ?? null;
        return $p && $p['action'] === 'proposed' && !isset($p['hit']) ? $p : null;
    }
}
