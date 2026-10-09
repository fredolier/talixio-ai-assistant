<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Decisions in embedding space (the JEPA idea applied cheaply): items are compared by their meaning
 * vectors to labelled examples, instead of asking a language model each time. A label wins when its
 * nearest examples are clearly closer than any other label's; otherwise the caller asks the model and
 * the user's choice becomes a new example. So it costs nothing per decision and learns from corrections.
 *
 * Examples are kept per task in the `examples` table (e.g. task "mail" with labels "reply", "fyi"...).
 * Library filing uses the library itself as its examples (every filed document is one).
 */
final class Classifier
{
    public function __construct(private App $app)
    {
        $app->store->db->exec('CREATE TABLE IF NOT EXISTS examples(id INTEGER PRIMARY KEY, task TEXT NOT NULL, label TEXT NOT NULL, ref TEXT NOT NULL DEFAULT \'\',
            model TEXT NOT NULL, vec BLOB NOT NULL, source TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS examples_task ON examples(task, model);');
    }

    public function enabled(): bool { return $this->app->embeddings->enabled(); }

    public function vector(string $text): string { return $this->app->embeddings->embed([$text], 'document')[0]; }

    /**
     * Rank labels for a vector against [label, vec] examples.
     * score(label) = 0.6 × best similarity + 0.4 × mean of its 3 best; confident when the best label
     * beats the runner-up by $margin and reaches $min similarity.
     * @param iterable<array{0:string,1:string}> $examples
     * @return array{label:?string, score:float, margin:float, confident:bool, ranking:array<string,float>, support:int}
     */
    public static function rank(string $vec, iterable $examples, float $min = 0.45, float $margin = 0.04, int $minSupport = 2): array
    {
        $sims = [];
        foreach ($examples as [$label, $v]) $sims[$label][] = Embeddings::dot($vec, $v);
        $scores = []; $support = [];
        foreach ($sims as $label => $list) {
            rsort($list);
            $top = array_slice($list, 0, 3);
            $scores[$label] = 0.6 * $top[0] + 0.4 * (array_sum($top) / count($top));
            $support[$label] = count($list);
        }
        arsort($scores);
        $labels = array_keys($scores);
        if (!$labels) return ['label' => null, 'score' => 0.0, 'margin' => 0.0, 'confident' => false, 'ranking' => [], 'support' => 0];
        $best = $labels[0]; $gap = $scores[$best] - ($scores[$labels[1] ?? ''] ?? 0.0);
        return ['label' => $best, 'score' => round($scores[$best], 4), 'margin' => round($gap, 4),
            'confident' => $scores[$best] >= $min && $gap >= $margin && $support[$best] >= $minSupport,
            'ranking' => array_map(fn($x) => round($x, 4), array_slice($scores, 0, 5, true)), 'support' => $support[$best]];
    }

    // ---- examples for other tasks (email triage...) ----

    public function learn(string $task, string $label, string $text, string $ref = '', string $source = 'user'): void
    {
        if (!$this->enabled() || trim($text) === '') return;
        $st = $this->app->store;
        if ($ref !== '') $st->q('DELETE FROM examples WHERE task = ? AND ref = ?', [$task, $ref]); // a correction replaces the earlier label
        $st->q('INSERT INTO examples(task, label, ref, model, vec, source, ts) VALUES(?,?,?,?,?,?,?)',
            [$task, $label, $ref, $this->app->embeddings->model(), $this->vector($text), $source, time()]);
        // keep the newest 2000 examples per task
        $st->q('DELETE FROM examples WHERE task = ? AND id NOT IN (SELECT id FROM examples WHERE task = ? ORDER BY id DESC LIMIT 2000)', [$task, $task]);
    }

    public function predict(string $task, string $text, float $min = 0.45, float $margin = 0.04): array
    {
        if (!$this->enabled()) return self::rank('', []);
        $rows = $this->app->store->q('SELECT label, vec FROM examples WHERE task = ? AND model = ?', [$task, $this->app->embeddings->model()])->fetchAll(\PDO::FETCH_NUM);
        return $rows ? self::rank($this->vector($text), $rows, $min, $margin) : self::rank('', []);
    }

    public function count(string $task): array
    {
        return array_column($this->app->store->q('SELECT label, COUNT(*) n FROM examples WHERE task = ? GROUP BY label', [$task])->fetchAll(), 'n', 'label');
    }
}
