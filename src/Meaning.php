<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Settings → Search by meaning: which service computes the vectors (presets in Embeddings::PRESETS), whether
 * it is ready (key, Ollama, model downloaded), how far the index is, and a measure of what it costs on this
 * server. Choosing another one re-indexes in the background (the vectors are tied to the model).
 */
final class Meaning
{
    public function __construct(private App $app) {}

    /** In the Docker stack: Ollama is shared, its models are downloaded from the admin page. */
    public static function shared(): bool { return Config::inFleet(); }

    public function status(): array
    {
        $c = $this->app->mainCfg; $st = $this->app->store;
        $ec = $this->app->embedCfg(); // the routing may point search by meaning at another model than the main settings
        $ollama = $this->ollama();
        $presets = [];
        foreach (Embeddings::PRESETS as $k => $p) {
            $why = match ($p['backend']) {
                'ollama' => $ollama['up'] ? (self::installed($p['model'], $ollama['models']) ? null : 'not-downloaded') : 'no-ollama',
                'gemini' => $c->geminiKey === '' ? 'no-key' : null,
                'openai' => $c->openaiKey === '' ? 'no-key' : null,
                'voyage' => $c->voyageKey === '' ? 'no-key' : null,
                'sovereign' => $c->sovereignReady() ? null : 'no-key',
                default => null,
            };
            $presets[] = ['id' => $k, 'local' => $p['backend'] === 'ollama'] + $p + ['problem' => $why];
        }
        $model = $this->app->embeddings->model();
        $docs = (int)$st->q('SELECT COUNT(*) FROM docs')->fetchColumn();
        $done = $ec->embeddings === 'off' ? 0 : (int)$st->q('SELECT COUNT(*) FROM embeddings WHERE model = ?', [$model])->fetchColumn();
        return [
            'current' => Embeddings::presetOf($ec), 'backend' => $ec->embeddings, 'model' => $ec->embeddingsModel, 'routed' => $this->app->binding('embedding') !== null, 'service' => $ec->sovereign,
            'presets' => $presets, 'shared' => self::shared(), 'ollama' => $ollama,
            'index' => ['done' => min($done, $docs), 'total' => $docs],
            'engine' => Recall::vecEngine($st->path),
            'measure' => json_decode($st->get('meaning_measure', ''), true) ?: null,
            'pull' => json_decode($st->get('meaning_pull', ''), true) ?: null,
        ];
    }

    /** Ollama: does it answer, which models does it have. */
    public function ollama(): array
    {
        $url = $this->app->mainCfg->ollamaUrl;
        try {
            [$code, $out] = Http::request('GET', "$url/api/tags", [], null, 3);
            if ($code !== 200) return ['up' => false, 'models' => [], 'url' => $url];
            return ['up' => true, 'models' => array_column(json_decode($out, true)['models'] ?? [], 'name'), 'url' => $url];
        } catch (\Throwable) {
            return ['up' => false, 'models' => [], 'url' => $url];
        }
    }

    /** "embeddinggemma" is listed as "embeddinggemma:latest". */
    public static function installed(string $model, array $models): bool
    {
        $want = str_contains(basename($model), ':') ? $model : "$model:latest";
        foreach ($models as $m) if (strcasecmp($m, $want) === 0 || strcasecmp($m, $model) === 0) return true;
        return false;
    }

    /** Use a preset. A local model not downloaded yet is fetched first (this server's own Ollama only). */
    public function choose(string $id): array
    {
        $p = Embeddings::PRESETS[$id] ?? throw new \InvalidArgumentException('unknown choice');
        $c = $this->app->mainCfg;
        if (in_array($p['backend'], ['gemini', 'openai', 'voyage'], true) && ($p['backend'] === 'gemini' ? $c->geminiKey : ($p['backend'] === 'openai' ? $c->openaiKey : $c->voyageKey)) === '')
            throw new \InvalidArgumentException('Add the ' . $p['label'] . ' key first (Settings → AI model, or Web search and memory for Voyage).');
        if ($p['backend'] === 'sovereign' && !$c->sovereignReady()) throw new \InvalidArgumentException('Set up the European provider first (Settings → AI model).');
        if ($p['backend'] === 'sovereign') $p['model'] = Sovereign::info($c->sovereign)['embed'];
        $err = Settings::save($c->dataDir, ['TALIXIO_EMBEDDINGS' => $p['backend'], 'TALIXIO_EMBEDDINGS_MODEL' => $p['model']], ['TALIXIO_EMBEDDINGS_DIMS']);
        if ($err) throw new \RuntimeException(implode(' ', $err));
        $this->app->routing()->set(null, 'embedding', null); // this choice replaces a model picked in the Models table
        $this->app->store->set('meaning_measure', ''); // measured with the previous one
        Log::info("search by meaning: now $id ({$p['backend']} {$p['model']})");
        $pulling = false;
        if ($p['backend'] === 'ollama' && !self::shared()) {
            $o = $this->ollama();
            if ($o['up'] && !self::installed($p['model'], $o['models'])) { $this->startPull($p['model']); $pulling = true; }
        }
        return ['ok' => true, 'pulling' => $pulling];
    }

    public function startPull(string $model): void
    {
        if (self::shared()) throw new \InvalidArgumentException('On this server the models are downloaded by the administrator (admin page → Settings → Shared memory search).');
        $this->app->store->set('meaning_pull', json_encode(['model' => $model, 'state' => 'running', 'ts' => time()]));
        Background::start([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'embeddings-pull', $model], $this->app->mainCfg->dataDir . '/embeddings-pull.log');
    }

    /** The background download (talixio embeddings-pull MODEL). */
    public function pull(string $model): void
    {
        try {
            [$code, $out] = Http::request('POST', $this->app->mainCfg->ollamaUrl . '/api/pull', ['Content-Type: application/json'], json_encode(['model' => $model, 'stream' => false]), 3600);
            $d = json_decode($out, true);
            if ($code !== 200 || ($d['status'] ?? '') !== 'success') throw new \RuntimeException($d['error'] ?? "HTTP $code");
            $this->app->store->set('meaning_pull', json_encode(['model' => $model, 'state' => 'done', 'ts' => time()]));
            Log::info("ollama: $model downloaded");
        } catch (\Throwable $e) {
            $this->app->store->set('meaning_pull', json_encode(['model' => $model, 'state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'ts' => time()]));
            Log::warn("ollama pull $model: {$e->getMessage()}");
        }
    }

    /**
     * What the current choice costs here: a search (the question turned into a vector, first call and warm), indexing
     * (per passage of about 1 000 characters) and comparing with every stored vector.
     */
    public function measure(): array
    {
        $emb = $this->app->embeddings;
        if (!$emb->enabled()) throw new \InvalidArgumentException('Search by meaning is off: nothing to measure.');
        $ms = fn(float $t) => (int)round((microtime(true) - $t) * 1000);
        $t = microtime(true); $q = $emb->embed(['Quand dois-je renouveler le contrat d\'assurance de la maison ?'], 'query')[0]; $first = $ms($t);
        $t = microtime(true); $emb->embed(['Quel est le montant de la dernière facture d\'électricité ?'], 'query'); $query = $ms($t);
        $passage = str_repeat('Le présent contrat est conclu pour une durée d\'un an, renouvelable par tacite reconduction, sauf résiliation par l\'une des parties avec un préavis de deux mois. ', 7);
        $t = microtime(true); $emb->embed(array_fill(0, 4, $passage), 'document'); $doc = (int)round($ms($t) / 4);
        $engine = Recall::vecEngine($this->app->store->path);
        $n = (int)$this->app->store->q('SELECT COUNT(*) FROM embeddings WHERE model = ?', [$emb->model()])->fetchColumn();
        $t = microtime(true); $this->app->recall()->similarities($q); $scan = $ms($t);
        $r = ['ts' => time(), 'preset' => Embeddings::presetOf($this->app->mainCfg), 'first_ms' => $first, 'query_ms' => $query, 'passage_ms' => $doc,
            'scan_ms' => $scan, 'vectors' => $n, 'engine' => $engine, 'search_ms' => $query + $scan];
        $this->app->store->set('meaning_measure', json_encode($r));
        return $r;
    }
}
