<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Background model calls through Mistral's batch API, at half the price: summaries and the Mind's
 * consolidation and portraits, where an answer within a few hours is fine.
 * A request is sent once under a stable key (e.g. "compact:3:120"); the worker polls the jobs (poll())
 * and the caller, on its next try with the same key, collects the answer. Without a Mistral account,
 * with the setting off, or when the budget runs low, the call is made directly, as before.
 * A batch that fails or expires is made again directly.
 */
final class Batch
{
    private const DONE = ['SUCCESS'];
    private const FAILED = ['FAILED', 'TIMEOUT_EXCEEDED', 'CANCELLED', 'CANCELLATION_REQUESTED'];

    public function __construct(private App $app, private bool $direct = false)
    {
        $app->store->q('CREATE TABLE IF NOT EXISTS batches(key TEXT PRIMARY KEY, space INTEGER, job TEXT, model TEXT, tier TEXT, source TEXT,
            status TEXT, text TEXT, meta TEXT, created INTEGER, checked INTEGER)');
    }

    /** Whether a call made now in the current space would go through the batch API. */
    public function eligible(): bool
    {
        $c = $this->app->cfg;
        return !$this->direct && $c->mistralBatch && $c->provider === 'sovereign' && $c->sovereign === 'mistral'
            && $c->sovereignUrl !== '' && $this->app->budget()->status()['state'] === 'ok';
    }

    /**
     * Ask the model for $key: ['done', ?text, meta] with the answer (null when there is none), or ['waiting', null, meta]
     * while the batch runs. $meta is kept with the request and given back with its answer.
     */
    public function ask(string $key, string $role, string $source, string $tierName, string $prompt, array $meta = []): array
    {
        $row = $this->row($key);
        if ($row) {
            $stored = json_decode((string)$row['meta'], true) ?: [];
            if ($row['status'] === 'done') { $this->drop($key); return ['done', $row['text'] !== '' ? $row['text'] : null, $stored]; }
            if ($row['status'] === 'waiting' && !$this->direct) return ['waiting', null, $stored];
            if ($row['status'] === 'waiting') $this->cancel($row); // asked for now: no waiting for the batch
            $this->drop($key); // failed or expired: made again directly
            return ['done', $this->now($role, $source, $tierName, $prompt), $stored];
        }
        if (!$this->eligible()) return ['done', $this->now($role, $source, $tierName, $prompt), $meta];
        try {
            $this->submit($key, $role, $source, $tierName, $prompt, $meta);
        } catch (\Throwable $e) {
            Log::warn("batch: {$e->getMessage()}; answered directly");
            return ['done', $this->now($role, $source, $tierName, $prompt), $meta];
        }
        return ['waiting', null, $meta];
    }

    /** The meta of the request sent for $key, if any. */
    public function meta(string $key): ?array
    {
        $row = $this->row($key);
        return $row ? (json_decode((string)$row['meta'], true) ?: []) : null;
    }

    public function drop(string $key): void { $this->app->store->q('DELETE FROM batches WHERE key = ?', [$key]); }

    /** Called by the worker: checks the running batches, collects their answers, forgets old ones. */
    public function poll(int $now): int
    {
        $st = $this->app->store;
        $n = 0;
        $before = $this->app->space;
        try {
            foreach ($st->q("SELECT * FROM batches WHERE status = 'waiting' AND checked <= ? ORDER BY created LIMIT 20", [$now - 50])->fetchAll() as $r) {
                $this->app->setSpace((int)$r['space']); // the account the batch was sent with
                $c = $this->app->cfg;
                if ($c->provider !== 'sovereign' || $c->sovereign !== 'mistral') { $this->set($r['key'], 'failed'); continue; }
                try {
                    $job = $this->get($c, '/batch/jobs/' . rawurlencode((string)$r['job']));
                    $status = strtoupper((string)($job['status'] ?? ''));
                    if (in_array($status, self::DONE, true)) { $this->collect($c, $r, $job); $n++; }
                    elseif (in_array($status, self::FAILED, true)) { Log::warn("batch {$r['key']}: $status"); $this->set($r['key'], 'failed'); }
                    else $st->q('UPDATE batches SET checked = ? WHERE key = ?', [$now, $r['key']]);
                } catch (\Throwable $e) {
                    Log::warn("batch {$r['key']}: {$e->getMessage()}");
                    $st->q('UPDATE batches SET checked = ? WHERE key = ?', [$now, $r['key']]);
                }
            }
        } finally { $this->app->setSpace($before); }
        $st->q("UPDATE batches SET status = 'failed' WHERE status = 'waiting' AND created < ?", [$now - 2 * 86400]); // Mistral gives up after a day
        $st->q("DELETE FROM batches WHERE status != 'waiting' AND created < ?", [$now - 3 * 86400]);
        return $n;
    }

    /** Requests waiting for their batch. */
    public function waiting(): int { return (int)$this->app->store->q("SELECT COUNT(*) FROM batches WHERE status = 'waiting'")->fetchColumn(); }

    // ---- inside ----

    private function now(string $role, string $source, string $tierName, string $prompt): ?string
    {
        try {
            [$text] = $this->app->agent()->run($source, $tierName, 'none', [['role' => 'user', 'content' => $prompt]], '', $role);
        } catch (Abort) {
            return null;
        }
        return $text === '' ? null : $text;
    }

    private function submit(string $key, string $role, string $source, string $tierName, string $prompt, array $meta): void
    {
        $c = $this->app->cfg;
        $tier = $c->tier($tierName);
        $line = json_encode(['custom_id' => '0', 'body' => ['max_tokens' => 4000, 'messages' => [
            ['role' => 'system', 'content' => $this->app->agent()->systemPrompt($role)],
            ['role' => 'user', 'content' => $prompt],
        ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $b = '----talixio' . bin2hex(random_bytes(8));
        $body = "--$b\r\nContent-Disposition: form-data; name=\"purpose\"\r\n\r\nbatch\r\n"
            . "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"talixio.jsonl\"\r\nContent-Type: application/jsonl\r\n\r\n$line\n\r\n--$b--\r\n";
        $file = $this->call('POST', $c, '/files', $body, "multipart/form-data; boundary=$b");
        if (!is_string($file['id'] ?? null)) throw new \RuntimeException('no file id');
        $job = $this->call('POST', $c, '/batch/jobs', json_encode(['input_files' => [$file['id']], 'model' => $tier->model,
            'endpoint' => '/v1/chat/completions', 'metadata' => ['talixio' => $key]], JSON_UNESCAPED_SLASHES), 'application/json');
        if (!is_string($job['id'] ?? null)) throw new \RuntimeException('no batch id');
        $this->app->store->q('INSERT OR REPLACE INTO batches(key, space, job, model, tier, source, status, text, meta, created, checked) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$key, $this->app->space, $job['id'], $tier->model, $tier->name, $source, 'waiting', '', json_encode($meta), time(), time()]);
    }

    private function cancel(array $r): void
    {
        $before = $this->app->space;
        try {
            $this->app->setSpace((int)$r['space']);
            $c = $this->app->cfg;
            if ($c->provider === 'sovereign' && $c->sovereign === 'mistral') $this->call('POST', $c, '/batch/jobs/' . rawurlencode((string)$r['job']) . '/cancel', '', 'application/json');
        } catch (\Throwable $e) {
            Log::warn("batch {$r['key']}: not cancelled: {$e->getMessage()}");
        } finally { $this->app->setSpace($before); }
    }

    private function collect(Config $c, array $r, array $job): void
    {
        $out = (string)($job['output_file'] ?? '');
        if ($out === '') { $this->set($r['key'], 'failed'); return; }
        [$code, $body] = Privacy::as('model', fn() => Http::request('GET', "{$c->sovereignUrl}/files/" . rawurlencode($out) . '/content', $this->auth($c), null, 60));
        if ($code >= 400) throw new \RuntimeException("API error $code: " . mb_substr($body, 0, 300));
        $text = null; $usage = null;
        foreach (preg_split('/\r?\n/', trim($body)) as $l) {
            $d = json_decode($l, true);
            if (!is_array($d) || (string)($d['custom_id'] ?? '') !== '0') continue;
            $resp = $d['response']['body'] ?? null;
            if ((int)($d['response']['status_code'] ?? 200) < 400 && is_array($resp)) {
                $text = trim((string)($resp['choices'][0]['message']['content'] ?? ''));
                $usage = $resp['usage'] ?? null;
            }
        }
        if ($text === null) { $this->set($r['key'], 'failed'); return; }
        if (is_array($usage)) {
            $u = ['model' => (string)$r['model'], 'in' => (int)($usage['prompt_tokens'] ?? 0), 'out' => (int)($usage['completion_tokens'] ?? 0), 'cache_read' => 0, 'cache_write' => 0];
            $cost = $this->app->pricing()->cost($u);
            $this->app->store->addUsage($u, $cost === null ? null : $cost / 2, (string)$r['source'] . ' (batch)', (string)$r['tier'], null); // half price
        }
        $this->app->store->q("UPDATE batches SET status = 'done', text = ? WHERE key = ?", [$text, $r['key']]);
    }

    private function call(string $method, Config $c, string $path, string $body, string $type): array
    {
        [$code, $resp] = Privacy::as('model', fn() => Http::request($method, $c->sovereignUrl . $path, array_merge($this->auth($c), ["Content-Type: $type"]), $body, 60));
        $d = json_decode($resp, true);
        if ($code >= 400 || !is_array($d)) throw new \RuntimeException("API error $code: " . mb_substr($resp, 0, 300));
        return $d;
    }

    private function get(Config $c, string $path): array
    {
        [$code, $resp] = Privacy::as('model', fn() => Http::request('GET', $c->sovereignUrl . $path, $this->auth($c), null, 30));
        $d = json_decode($resp, true);
        if ($code >= 400 || !is_array($d)) throw new \RuntimeException("API error $code: " . mb_substr($resp, 0, 300));
        return $d;
    }

    private function auth(Config $c): array { return $c->sovereignKey !== '' ? ['Authorization: Bearer ' . $c->sovereignKey] : []; }

    private function row(string $key): ?array { return $this->app->store->q('SELECT * FROM batches WHERE key = ?', [$key])->fetch() ?: null; }

    private function set(string $key, string $status): void { $this->app->store->q('UPDATE batches SET status = ? WHERE key = ?', [$status, $key]); }
}
