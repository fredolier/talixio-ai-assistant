<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The improvement gate (agent factory, phase 2 — see docs/design/agent-factory.md).
 *
 * Roles get better only if the numbers say so, and the numbers can't be gamed from inside:
 *  - the ANCHOR metric is real-world acceptance + cost (did the user accept/use it, at what cost). It is external
 *    and un-editable. A role declares proxy metrics too (tests pass, citations resolve, …), but a NEW proxy must
 *    backtest — correlate with the anchor on past runs — before it is trusted.
 *  - the ASSESSOR is role #0: it scores other roles and gates changes. It is itself a protected role; editing it,
 *    changing a role's metrics, a role gaining a new capability, or publishing to the fleet are human-gated.
 *
 * Phase 2 is the measurement spine: record outcomes (role_runs), keep golden cases, report health, flag gated
 * changes. Live A/B promotion (running old vs new on the golden cases) lands with the worker executor in phase 3,
 * on top of exactly this data.
 */
final class Assessor
{
    public const NAME = 'assessor';

    public function __construct(private App $app) {}

    /** The anchor is always present and never editable; it is not stored in the header. */
    public const ANCHOR = 'acceptance+cost';

    /** Create role #0 once, protected, with a fixed brief. Safe to call repeatedly. */
    public function seed(): void
    {
        $skills = $this->app->skills();
        if (($skills->meta(self::NAME)['kind'] ?? '') === 'role') return;
        $skills->save(self::NAME, 'Score roles against their metrics and gate improvements — the factory\'s quality bar',
            "You are role #0, the assessor. You judge whether another role is doing its job and whether a proposed change is an improvement.\n\n"
            . "- The anchor metric is **real-world acceptance + cost**: did the user accept/use the output, at what cost. It outranks every proxy and cannot be edited.\n"
            . "- A role's proxy metrics (tests pass, citations resolve, a rubric score) only count once they have **backtested** against the anchor.\n"
            . "- Promote a change only if it wins on the weighted metrics over the role's golden cases. Prefer the simpler role when results tie (parsimony).\n"
            . "- Never bless a change that edits the assessor, changes a role's metrics, gives a role a new capability, or publishes it — those are the user's to approve.",
            ['kind' => 'role', 'tier' => 'deep', 'protected' => '1', 'scope' => 'private', 'version' => '1']);
    }

    /** Is this role the assessor, or otherwise locked from silent edits? */
    public function isProtected(string $role): bool
    {
        return Skills::slug($role) === self::NAME || ($this->app->skills()->meta($role)['protected'] ?? '') === '1';
    }

    // ---------- the eval spec ----------

    /** Proxy metrics declared on the role, as name => weight (default 1). The anchor is added, un-weighted and first. */
    public function metrics(string $role): array
    {
        $out = [self::ANCHOR => ['weight' => null, 'anchor' => true]];
        foreach (array_filter(array_map('trim', explode(',', (string)($this->app->skills()->meta($role)['metrics'] ?? '')))) as $m) {
            [$name, $w] = array_pad(explode(':', $m, 2), 2, '1');
            $name = trim($name);
            if ($name !== '' && $name !== self::ANCHOR) $out[$name] = ['weight' => max(0.0, (float)$w), 'anchor' => false];
        }
        return $out;
    }

    // ---------- golden cases ----------

    public function addCase(string $role, string $title, string $input = '', string $rubric = ''): int
    {
        $this->app->store->q('INSERT INTO role_cases(ts, role, title, input, rubric) VALUES(?,?,?,?,?)',
            [time(), Skills::slug($role), trim($title), trim($input), trim($rubric)]);
        return (int)$this->app->store->db->lastInsertId();
    }

    /** @return array<array{id:int,title:string,input:string,rubric:string}> */
    public function cases(string $role): array
    {
        return $this->app->store->q('SELECT id, title, input, rubric FROM role_cases WHERE role = ? ORDER BY id', [Skills::slug($role)])->fetchAll();
    }

    // ---------- the anchor signal ----------

    /** Record one outcome of a role run. accepted: true/false/null (unknown yet). */
    public function record(string $role, ?bool $accepted, float $cost = 0.0, ?float $score = null, string $case = '', string $note = ''): void
    {
        $slug = Skills::slug($role);
        $version = (int)($this->app->skills()->meta($role)['version'] ?? 1);
        $this->app->store->q('INSERT INTO role_runs(ts, role, version, case_ref, accepted, cost_usd, score, note) VALUES(?,?,?,?,?,?,?,?)',
            [time(), $slug, $version, trim($case), $accepted === null ? null : (int)$accepted, $cost, $score, trim($note)]);
    }

    // ---------- improvement: live A/B on the golden cases ----------

    /** Propose a better brief for a role; queue an evaluation job that A/Bs it on the golden cases and promotes only if it wins. */
    public function improve(string $role, string $candidate): string
    {
        $meta = $this->app->skills()->meta($role);
        if (($meta['kind'] ?? '') !== 'role') return "error: no role '$role'. Create it with role_save first.";
        if (trim($candidate) === '') return 'error: give the candidate brief (the improved instructions) to evaluate.';
        if (!$this->cases($role)) return "error: role '$role' has no golden cases to judge on. Add some with role_case_add first — an improvement is only trusted if it wins on real reference tasks.";
        if ($this->isProtected($role)) return "error: '$role' is protected (e.g. the assessor); its changes are the user's to make directly, not through automatic promotion.";
        $cap = max($this->app->jobs()->cap(null), 1.0);
        $id = $this->app->store->addJob("Evaluate an improvement to '" . Skills::slug($role) . "'", json_encode(['role' => Skills::slug($role), 'candidate' => $candidate]),
            'deep', $cap, 'eval', null, (int)$this->app->space);
        return "evaluation job #$id queued: it runs the current and candidate briefs on '" . Skills::slug($role) . "'’s golden cases, scores both, and promotes the candidate only if it wins. The result comes back in the chat.";
    }

    /** Runs inside an 'eval' job: the A/B. Returns the summary; promotes the candidate in place when it wins and isn't gated. */
    public function runEvaluation(array $job, callable $guard): string
    {
        $d = json_decode((string)$job['instructions'], true) ?: [];
        $role = (string)($d['role'] ?? ''); $candidate = trim((string)($d['candidate'] ?? ''));
        $meta = $this->app->skills()->meta($role);
        if (($meta['kind'] ?? '') !== 'role' || $candidate === '') return "Evaluation cancelled: the role or candidate is missing.";
        $cases = array_slice($this->cases($role), 0, 4);
        if (!$cases) return "Evaluation cancelled: '$role' has no golden cases.";
        [, $current] = Skills::parse($this->app->skills()->read($role, false));
        $tier = in_array($meta['tier'] ?? '', Tier::NAMES, true) ? (string)$meta['tier'] : 'standard';
        $cost = 0.0;
        $run = function (string $brief, string $input) use (&$cost, $tier, $role, $job, $guard) {
            $task = "## Your brief\n$brief\n\n## Task\n" . ($input !== '' ? $input : 'Carry out the task this role exists for.');
            [$text, $m] = $this->app->agent()->run('job', $tier, 'job', [['role' => 'user', 'content' => $task]], "You are the '$role' role. Do the task; produce your result only.", 'job', (int)$job['id'], $guard);
            $cost += (float)($m['cost'] ?? 0); $guard($cost);
            return trim((string)$text);
        };
        $trials = [];
        foreach ($cases as $c) {
            $trials[] = ['case' => $c['title'], 'rubric' => $c['rubric'],
                'current' => $run($current, (string)$c['input']), 'candidate' => $run($candidate, (string)$c['input'])];
        }
        // one judge pass scores both outputs per case against the rubric
        $prompt = "You are the assessor. For each case, score how well the CURRENT and the CANDIDATE output meet the rubric, each from 0.0 (poor) to 1.0 (excellent). "
            . "Judge only the outputs against the rubric; ignore length and style unless the rubric asks. Reply with JSON only: {\"cases\":[{\"current\":0.0,\"candidate\":0.0}], \"why\":\"one line\"}.\n\n";
        foreach ($trials as $i => $tr) $prompt .= "### Case " . ($i + 1) . ": {$tr['case']}\nRubric: " . ($tr['rubric'] ?: '(a good, correct, complete result)') . "\n-- CURRENT --\n{$tr['current']}\n-- CANDIDATE --\n{$tr['candidate']}\n\n";
        [$verdict, $vm] = $this->app->agent()->run('job', 'deep', 'none', [['role' => 'user', 'content' => $prompt]], '', 'job', (int)$job['id'], $guard);
        $cost += (float)($vm['cost'] ?? 0); $guard($cost);
        $scores = json_decode((string)(preg_match('/\{.*\}/s', (string)$verdict, $mm) ? $mm[0] : ''), true);
        $rows = is_array($scores['cases'] ?? null) ? $scores['cases'] : [];
        if (count($rows) < count($trials)) return "Evaluation inconclusive: the judge did not score every case. The candidate was not promoted.";
        $cur = $cand = 0.0;
        foreach ($rows as $i => $r) {
            $cc = max(0.0, min(1.0, (float)($r['candidate'] ?? 0))); $pc = max(0.0, min(1.0, (float)($r['current'] ?? 0)));
            $cur += $pc; $cand += $cc;
            $this->record($role, null, 0.0, $cc, $trials[$i]['case'], 'A/B candidate'); // candidate scores become anchor-linkable samples
        }
        $n = count($rows); $cur /= $n; $cand /= $n;
        $won = $cand >= $cur + 0.05; // a clear win, not noise
        $gated = $this->gate($role, $meta, $meta); // brief-only change; only a protected role blocks here
        $head = sprintf("A/B on %d golden case(s) of '%s': current %.2f vs candidate %.2f.", $n, $role, $cur, $cand);
        if ($won && !$gated) {
            $extra = $meta; unset($extra['name'], $extra['description']);
            $extra['version'] = (string)(((int)($meta['version'] ?? 0)) + 1);
            $this->app->skills()->save($role, $meta['description'] ?? 'role', $candidate, array_filter($extra, fn($v) => trim((string)$v) !== ''));
            return "$head Candidate wins — promoted to v{$extra['version']}." . (isset($scores['why']) ? ' ' . $scores['why'] : '');
        }
        return "$head " . ($won ? 'Candidate wins but the change is gated (' . implode('; ', $gated) . '); kept the current version pending your approval.' : 'Candidate does not clearly beat the current brief; kept the current version.') . (isset($scores['why']) ? ' ' . $scores['why'] : '');
    }

    // ---------- health ----------

    /**
     * How a role is doing, from its recorded runs: acceptance rate, mean cost, the trend of recent vs earlier runs,
     * plus near-duplicates in the registry. The honest read a human (or a director) uses to decide what to improve.
     */
    public function health(string $role, int $window = 40): array
    {
        $slug = Skills::slug($role);
        $rows = $this->app->store->q('SELECT accepted, cost_usd, score FROM role_runs WHERE role = ? ORDER BY id DESC LIMIT ?', [$slug, $window])->fetchAll();
        $rated = array_values(array_filter($rows, fn($r) => $r['accepted'] !== null));
        $acc = $rated ? array_sum(array_map(fn($r) => (int)$r['accepted'], $rated)) / count($rated) : null;
        $cost = $rows ? array_sum(array_map(fn($r) => (float)$r['cost_usd'], $rows)) / count($rows) : null;
        $dupes = array_values(array_filter($this->app->skills()->find($this->app->skills()->meta($role)['description'] ?? $role, 4, 'role'),
            fn($h) => Skills::slug($h['name']) !== $slug && $h['score'] >= 0.6));
        return [
            'role' => $slug, 'runs' => count($rows), 'acceptance' => $acc === null ? null : round($acc, 2),
            'cost_mean' => $cost === null ? null : round($cost, 4), 'trend' => $this->trend($rated),
            'cases' => count($this->cases($role)), 'metrics' => array_keys($this->metrics($role)),
            'duplicates' => array_column($dupes, 'name'),
        ];
    }

    /** Recent acceptance vs the earlier half: up / down / flat / new (too few rated runs to tell). */
    private function trend(array $rated): string
    {
        $n = count($rated);
        if ($n < 6) return 'new';
        $recent = array_slice($rated, 0, intdiv($n, 2)); // rows are newest-first
        $older = array_slice($rated, intdiv($n, 2));
        $mean = fn($a) => $a ? array_sum(array_map(fn($r) => (int)$r['accepted'], $a)) / count($a) : 0.0;
        $d = $mean($recent) - $mean($older);
        return $d > 0.1 ? 'up' : ($d < -0.1 ? 'down' : 'flat');
    }

    /**
     * Backtest a proxy metric before trusting it: how well does its per-run score line up with the anchor (accepted)?
     * Returns the correlation in [-1, 1], or null when there are too few paired runs to say. A new proxy is adopted
     * only when this is clearly positive — this is how good metrics are discovered, not invented to flatter.
     */
    public function backtest(string $role, string $metric, int $min = 5): ?float
    {
        // the proxy's per-run value is the run's `score`; pair it with `accepted`
        $rows = $this->app->store->q('SELECT accepted, score FROM role_runs WHERE role = ? AND accepted IS NOT NULL AND score IS NOT NULL', [Skills::slug($role)])->fetchAll();
        if (count($rows) < $min) return null;
        $xs = array_map(fn($r) => (float)$r['score'], $rows);
        $ys = array_map(fn($r) => (float)(int)$r['accepted'], $rows);
        return self::corr($xs, $ys);
    }

    /** Pearson correlation; null if either side has no spread. */
    public static function corr(array $xs, array $ys): ?float
    {
        $n = count($xs);
        if ($n === 0) return null;
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        $sxy = $sx = $sy = 0.0;
        for ($i = 0; $i < $n; $i++) { $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my; $sxy += $dx * $dy; $sx += $dx * $dx; $sy += $dy * $dy; }
        if ($sx == 0.0 || $sy == 0.0) return null;
        return round($sxy / sqrt($sx * $sy), 2);
    }

    // ---------- the gate ----------

    /**
     * Which human-approval triggers a proposed change fires, comparing the new header against the current one.
     * The registry entry itself is inert (a role has no power until a worker runs it, and that path keeps the
     * existing app/connector approval + space isolation), so these are surfaced for review, not a security barrier.
     * @return string[] human-readable reasons, empty when the change is ordinary and may be applied directly
     */
    public function gate(string $role, array $newMeta, array $oldMeta): array
    {
        $reasons = [];
        if ($this->isProtected($role)) $reasons[] = 'edits the assessor (role #0) or another protected role';
        $list = fn($s) => array_filter(array_map('trim', explode(',', (string)$s)));
        $added = array_diff($list($newMeta['tools'] ?? ''), $list($oldMeta['tools'] ?? ''));
        if ($added) $reasons[] = 'gives the role new capabilities: ' . implode(', ', $added);
        if (trim((string)($newMeta['metrics'] ?? '')) !== trim((string)($oldMeta['metrics'] ?? ''))) $reasons[] = 'changes the role\'s metrics';
        if (($newMeta['scope'] ?? 'private') === 'shared' && ($oldMeta['scope'] ?? 'private') !== 'shared') $reasons[] = 'publishes the role to the fleet';
        return $reasons;
    }
}
