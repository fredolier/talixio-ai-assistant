<?php
declare(strict_types=1);
namespace Talixio;

/** Hybrid search over past conversations and summaries: SQLite FTS5 keywords + embeddings, merged by reciprocal rank fusion. */
final class Recall
{
    private const STOP = ['the','and','for','you','that','this','with','what','are','was','have','not','but','can','des','les','une','est','que','qui','pour','dans','pas','sur','vous','avec','mais','nous','mes','tes','ses'];

    public function __construct(private Store $store, private Embeddings $emb, private ?App $app = null) {}

    /** Build a safe FTS5 query: significant words OR-ed together. */
    public static function ftsQuery(string $q): string
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($q), $m);
        $words = array_values(array_unique(array_diff($m[0], self::STOP)));
        return implode(' OR ', array_map(fn($w) => '"' . $w . '"', array_slice($words, 0, 12)));
    }

    /**
     * @param int $beforeMessageId ignore messages with id >= this (they are already in the prompt)
     * @return array<array{kind:string,ref:int,ts:int,label:string,text:string,score:float,sim:?float,keyword:bool}>
     */
    /** @param int|null $space conversations of this space only (documents are shared); null = every space */
    public function search(string $query, int $limit = 8, int $beforeMessageId = PHP_INT_MAX, ?array $kinds = null, ?array $refs = null, ?int $space = null, bool $meaning = true): array
    {
        $refSet = $refs !== null ? array_flip($refs) : null;
        $exclude = fn(array $d) => ($d['kind'] === 'message' && (int)$d['ref'] >= $beforeMessageId) || ($space !== null && in_array($d['kind'], ['message', 'summary'], true) && (int)$d['space'] !== $space)
            || ($kinds !== null && !in_array($d['kind'], $kinds, true)) || ($refSet !== null && !isset($refSet[(int)$d['ref']]));
        if ($refs === []) return [];
        // when restricted to some sources, filter inside SQL so other documents do not crowd them out
        $only = ''; $onlyArgs = [];
        if ($kinds !== null) { $only .= ' AND d.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')'; $onlyArgs = $kinds; }
        if ($refs !== null && count($refs) <= 900) { $only .= ' AND d.ref IN (' . implode(',', array_map('intval', $refs)) . ')'; }
        if ($space !== null) { $only .= " AND (d.kind NOT IN ('message','summary') OR d.space = ?)"; $onlyArgs[] = $space; }
        $hits = [];
        $fq = self::ftsQuery($query);
        if ($fq !== '') {
            $rows = $this->store->q("SELECT d.* FROM docs_fts f JOIN docs d ON d.id = f.rowid WHERE docs_fts MATCH ?$only ORDER BY bm25(docs_fts) LIMIT 40", [$fq, ...$onlyArgs])->fetchAll();
            $rank = 0;
            foreach ($rows as $d) {
                if ($exclude($d)) continue;
                $hits[$d['id']] = $d + ['score' => 1 / (60 + ++$rank), 'sim' => null, 'keyword' => true];
            }
        }
        if ($meaning && $this->emb->enabled()) { // without it: keywords only, instant (the library shows those first)
            try {
                $q = $this->emb->embed([$query], 'query')[0];
                $sims = [];
                $rows = $only === ''
                    ? $this->store->q('SELECT e.doc_id, e.vec FROM embeddings e WHERE e.model = ? ORDER BY e.doc_id DESC LIMIT 20000', [$this->emb->model()])->fetchAll()
                    : $this->store->q("SELECT e.doc_id, e.vec FROM embeddings e JOIN docs d ON d.id = e.doc_id WHERE e.model = ?$only ORDER BY e.doc_id DESC LIMIT 20000", [$this->emb->model(), ...$onlyArgs])->fetchAll();
                foreach ($rows as $r) $sims[$r['doc_id']] = Embeddings::dot($q, $r['vec']);
                arsort($sims);
                $rank = 0;
                foreach (array_slice($sims, 0, 60, true) as $id => $sim) {
                    $d = $hits[$id] ?? $this->store->q('SELECT * FROM docs WHERE id=?', [$id])->fetch();
                    if (!$d || $exclude($d)) continue;
                    $hits[$id] = ($hits[$id] ?? $d + ['score' => 0.0, 'keyword' => false]);
                    $hits[$id]['score'] += 1 / (60 + ++$rank);
                    $hits[$id]['sim'] = $sim;
                    if ($rank >= 40) break;
                }
            } catch (\Throwable $e) {
                Log::warn("embeddings search: {$e->getMessage()}");
            }
        }
        uasort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        if ($this->app) { // secret spaces are only searched from inside; the rest of the work follows the level of what it found
            $p = $this->app->privacy(); $kept = [];
            foreach ($hits as $id => $h) {
                $lvl = $p->docLevel($h);
                if ($p->hidden($lvl, in_array($h['kind'], ['message', 'summary'], true) ? (int)$h['space'] : null, null)) continue;
                $kept[$id] = $h + ['level' => $lvl];
                if (count($kept) >= $limit) break;
            }
            $hits = $kept;
            foreach ($hits as $h) Privacy::raise($h['level']);
        }
        return array_values(array_slice($hits, 0, $limit));
    }

    public function meaningEnabled(): bool { return $this->emb->enabled(); }

    /** Snippets worth adding to the prompt automatically: keyword matches or clearly similar meaning. */
    public function autoRecall(string $query, int $beforeMessageId, float $minSim, int $space = 0): string
    {
        $hits = array_filter($this->search($query, 6, $beforeMessageId, null, null, $space), fn($h) => $h['keyword'] || ($h['sim'] ?? 0) >= $minSim);
        return self::format(array_slice($hits, 0, 4), 500);
    }

    public static function format(array $hits, int $chars = 800): string
    {
        return implode("\n", array_map(fn($h) => sprintf('- [%s, %s] %s', date('Y-m-d H:i', (int)$h['ts']), $h['label'],
            preg_replace('/\s+/', ' ', mb_substr($h['text'], 0, $chars))), $hits));
    }

    /**
     * Compute missing embeddings, newest first (called by the worker). Also re-indexes everything after a
     * model change. Stops after $seconds so a slow local model never blocks the worker for long.
     */
    public function embedPending(int $batch = 8, float $seconds = 8.0, int $max = 10000): int
    {
        if (!$this->emb->enabled()) return 0;
        $model = $this->emb->model();
        $done = 0; $until = microtime(true) + $seconds;
        // confidential and secret content is only indexed by meaning on this server (Ollama), never by a service outside
        $skip = $this->app && !$this->emb->local($this->app) ? $this->app->privacy()->noOutsideEmbedding() : [];
        $not = $skip ? ' AND d.id NOT IN (' . implode(',', $skip) . ')' : '';
        do {
            $docs = $this->store->q("SELECT d.id, d.text FROM docs d WHERE NOT EXISTS (SELECT 1 FROM embeddings e WHERE e.doc_id = d.id AND e.model = ?)$not ORDER BY d.id DESC LIMIT ?",
                [$model, $batch])->fetchAll();
            if (!$docs) break;
            $vecs = $this->emb->embed(array_column($docs, 'text'), 'document');
            foreach ($docs as $i => $d) {
                if (isset($vecs[$i])) $this->store->q('INSERT OR REPLACE INTO embeddings(doc_id, model, vec) VALUES(?,?,?)', [$d['id'], $model, $vecs[$i]]);
            }
            $done += count($docs);
        } while (microtime(true) < $until && $done < $max);
        return $done;
    }

    /** [indexed for the current model, total] */
    public function coverage(): array
    {
        return [(int)$this->store->q('SELECT COUNT(*) FROM embeddings WHERE model = ?', [$this->emb->model()])->fetchColumn(),
            (int)$this->store->q('SELECT COUNT(*) FROM docs')->fetchColumn()];
    }
}
