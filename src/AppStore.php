<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The server's store as one assistant sees it (several people on one server, TALIXIO_STORE): the apps and connectors
 * the admin approved, to install or remove, and what the person sends for review. Sending only puts it in an outbox in
 * this assistant's data; the agent collects it when the admin opens the store, and tells back what was decided.
 * An app installed from the store waits for the person's approval like any other.
 */
final class AppStore
{
    public function __construct(private App $app) {}

    public static function dir(): string { return rtrim((string)getenv('TALIXIO_STORE'), '/'); }

    public function available(): bool { return self::dir() !== '' && is_dir(self::dir()); }

    public function catalog(): array
    {
        $c = $this->available() ? json_decode((string)@file_get_contents(self::dir() . '/catalog.json'), true) : null;
        return ['apps' => array_values((array)($c['apps'] ?? [])), 'connectors' => array_values((array)($c['connectors'] ?? []))];
    }

    private function outDir(): string { return $this->app->cfg->dataDir . '/store-outbox'; }

    /** What the person sent, newest first. $full: with what was sent (for the agent). */
    public function outbox(bool $full = false): array
    {
        $out = [];
        foreach (glob($this->outDir() . '/*.json') ?: [] as $f) {
            $it = json_decode((string)file_get_contents($f), true);
            if (!is_array($it)) continue;
            $out[] = $full ? $it : array_diff_key($it, ['package' => 1, 'recipe' => 1]);
        }
        usort($out, fn($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
        return $out;
    }

    private function put(array $it): array
    {
        if (!$this->available()) throw new \InvalidArgumentException('This assistant has no store.');
        @mkdir($this->outDir(), 0700, true);
        file_put_contents($this->outDir() . "/{$it['key']}.json", json_encode($it, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return array_diff_key($it, ['package' => 1, 'recipe' => 1]);
    }

    /** An app sent for review, as the package shared with others (no data, no key). */
    public function submitApp(int $id, string $note = ''): array
    {
        $pkg = $this->app->apps()->export($id);
        return $this->put(['key' => "app-$id", 'kind' => 'app', 'name' => $pkg['name'], 'package' => $pkg, 'note' => mb_substr(trim($note), 0, 500), 'at' => time(), 'status' => 'sent']);
    }

    /** A connector sent for review: how to install it, never its keys or header values. */
    public function submitConnector(string $server, string $note = ''): array
    {
        $srv = $this->app->mcp()->servers()[$server] ?? throw new \InvalidArgumentException("No connector named $server.");
        $r = Apps::recipe($srv);
        if (!empty($r['manual'])) throw new \InvalidArgumentException('A connector installed from a .zip or a command cannot go to the store: only one from a GitHub repository or an https address.');
        return $this->put(['key' => 'mcp-' . preg_replace('/[^\w-]+/', '-', $server), 'kind' => 'connector', 'name' => $server, 'recipe' => ['name' => $server] + $r,
            'note' => mb_substr(trim($note), 0, 500), 'at' => time(), 'status' => 'sent']);
    }

    public function withdraw(string $key): void
    {
        if (preg_match('/^(app|mcp)-[\w-]{1,60}$/', $key)) @unlink($this->outDir() . "/$key.json");
    }

    /** The admin's decision, told by the agent; only for the version that was reviewed. */
    public function mark(string $key, string $hash, string $status, string $note = ''): bool
    {
        if (!preg_match('/^(app|mcp)-[\w-]{1,60}$/', $key) || !in_array($status, ['approved', 'rejected'], true)) return false;
        $f = $this->outDir() . "/$key.json";
        $it = json_decode((string)@file_get_contents($f), true);
        if (!is_array($it) || Catalog::hash($it) !== $hash) return false;
        $it['status'] = $status; $it['reason'] = mb_substr($note, 0, 300); $it['decided'] = time();
        file_put_contents($f, json_encode($it, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Store app id => [app id here, version installed], over every space. */
    private function installedApps(): array
    {
        $out = [];
        $this->app->apps(); // make sure the apps table exists before we read it
        foreach ($this->app->store->q("SELECT id, origin FROM apps WHERE origin LIKE '%\"store\"%'")->fetchAll() as $r) {
            $o = json_decode((string)$r['origin'], true);
            if (isset($o['store'])) $out[(string)$o['store']] = ['id' => (int)$r['id'], 'version' => (int)($o['store_version'] ?? 1)];
        }
        return $out;
    }

    /** The store's items, each with whether it is installed here (and an update waiting), and the outbox. */
    public function view(): array
    {
        $c = $this->catalog(); $have = $this->installedApps(); $visible = array_column($this->app->apps()->list(), null, 'id');
        $apps = array_map(function ($a) use ($have, $visible) {
            $h = $have[$a['id']] ?? null;
            if ($h && !isset($visible[$h['id']])) $h = null; // in a Secret space: not from here
            return array_diff_key($a, ['package' => 1]) + ['installed' => $h['id'] ?? null, 'update' => $h !== null && $h['version'] < (int)($a['version'] ?? 1),
                'sources' => array_map(fn($s) => array_intersect_key((array)$s, array_flip(['name', 'type', 'url', 'path', 'server', 'tool', 'folder'])), (array)($a['package']['sources'] ?? []))];
        }, $c['apps']);
        $servers = $this->app->mcp()->servers();
        $conns = array_map(function ($x) use ($servers) {
            $at = null;
            foreach ($servers as $n => $s) {
                $r = Apps::recipe($s);
                if ((isset($x['github']) && rtrim(strtolower($r['github'] ?? ''), '/') === rtrim(strtolower($x['github']), '/')) || (isset($x['url']) && ($r['url'] ?? '') === $x['url'])) { $at = $n; break; }
            }
            return $x + ['installed' => $at];
        }, $c['connectors']);
        return ['available' => $this->available(), 'shared' => Config::inFleet(), 'apps' => $apps, 'connectors' => $conns, 'outbox' => $this->outbox()];
    }

    /** Installs a store app in this space, or brings the installed copy to the store's version: it waits for approval. */
    public function install(string $id): array
    {
        $item = current(array_filter($this->catalog()['apps'], fn($a) => $a['id'] === $id)) ?: throw new \InvalidArgumentException('Not in the store (any more).');
        $have = $this->installedApps()[$id] ?? null;
        if ($have && !$this->app->apps()->get($have['id'])) $have = null;
        return $this->app->apps()->import((array)$item['package'], 'Store', $have['id'] ?? null, ['store' => $id, 'store_version' => (int)($item['version'] ?? 1)]);
    }
}
