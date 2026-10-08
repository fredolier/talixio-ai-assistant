<?php
declare(strict_types=1);
namespace Talixio;

/** Hybrid search over past conversations and summaries: SQLite FTS5 keywords + embeddings, merged by reciprocal rank fusion. */
final class Recall
{
    private const STOP = ['the','and','for','you','that','this','with','what','are','was','have','not','but','can','des','les','une','est','que','qui','pour','dans','pas','sur','vous','avec','mais','nous','mes','tes','ses'];

    public function __construct(private Store $store, private Embeddings $emb) {}

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
    public function search(string $query, int $limit = 8, int $beforeMessageId = PHP_INT_MAX): array
    {
        $exclude = fn(array $d) => $d['kind'] === 'message' && (int)$d['ref'] >= $beforeMessageId;
        $hits = [];
        $fq = self::ftsQuery($query);
        if ($fq !== '') {
            $rows = $this->store->q("SELECT d.* FROM docs_fts f JOIN docs d ON d.id = f.rowid WHERE docs_fts MATCH ? ORDER BY bm25(docs_fts) LIMIT 40", [$fq])->fetchAll();
            $rank = 0;
            foreach ($rows as $d) {
                if ($exclude($d)) continue;
                $hits[$d['id']] = $d + ['score' => 1 / (60 + ++$rank), 'sim' => null, 'keyword' => true];
            }
        }
        if ($this->emb->enabled()) {
            try {
                $q = $this->emb->embed([$query], 'query')[0];
                $sims = [];
                $rows = $this->store->q('SELECT e.doc_id, e.vec FROM embeddings e ORDER BY e.doc_id DESC LIMIT 20000')->fetchAll();
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
        return array_values(array_slice($hits, 0, $limit));
    }

    /** Snippets worth adding to the prompt automatically: keyword matches or clearly similar meaning. */
    public function autoRecall(string $query, int $beforeMessageId, float $minSim): string
    {
        $hits = array_filter($this->search($query, 6, $beforeMessageId), fn($h) => $h['keyword'] || ($h['sim'] ?? 0) >= $minSim);
        return self::format(array_slice($hits, 0, 4), 500);
    }

    public static function format(array $hits, int $chars = 800): string
    {
        return implode("\n", array_map(fn($h) => sprintf('- [%s, %s] %s', date('Y-m-d H:i', (int)$h['ts']), $h['label'],
            preg_replace('/\s+/', ' ', mb_substr($h['text'], 0, $chars))), $hits));
    }

    /** Compute missing embeddings (called by the worker). Returns how many were added. */
    public function embedPending(int $batch = 32): int
    {
        if (!$this->emb->enabled()) return 0;
        $docs = $this->store->q('SELECT id, text FROM docs WHERE id NOT IN (SELECT doc_id FROM embeddings) ORDER BY id DESC LIMIT ?', [$batch])->fetchAll();
        if (!$docs) return 0;
        $vecs = $this->emb->embed(array_column($docs, 'text'), 'document');
        $model = $this->emb->model();
        foreach ($docs as $i => $d) {
            if (isset($vecs[$i])) $this->store->q('INSERT OR REPLACE INTO embeddings(doc_id, model, vec) VALUES(?,?,?)', [$d['id'], $model, $vecs[$i]]);
        }
        return count($docs);
    }
}
