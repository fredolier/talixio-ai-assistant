<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The server's store (several people on one server): apps and connectors the admin approved, which every assistant can
 * install. The agent writes it to a volume each assistant mounts read-only (catalog.json). What people send for review
 * waits in the agent's own state, never in that volume: an assistant sees only what the admin published.
 */
final class Catalog
{
    private const MAX_DONE = 100; // decided submissions kept for the history

    public function __construct(private string $dir, private string $stateDir) {}

    public function read(): array
    {
        $c = json_decode((string)@file_get_contents("$this->dir/catalog.json"), true);
        return ['apps' => array_values((array)($c['apps'] ?? [])), 'connectors' => array_values((array)($c['connectors'] ?? [])), 'updated' => (int)($c['updated'] ?? 0)];
    }

    /** What people sent: pending first, then the latest decisions. */
    public function submissions(): array
    {
        return array_values((array)($this->state()['subs'] ?? []));
    }

    private function state(): array
    {
        $s = json_decode((string)@file_get_contents("$this->stateDir/store.json"), true);
        return is_array($s) ? $s + ['subs' => [], 'links' => []] : ['subs' => [], 'links' => []];
    }

    private static function write(string $file, array $d, int $mode): void
    {
        @mkdir(dirname($file), 0755, true);
        file_put_contents("$file.tmp", json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod("$file.tmp", $mode);
        rename("$file.tmp", $file);
    }

    private function saveState(array $s): void
    {
        $pending = array_filter($s['subs'], fn($x) => $x['status'] === 'pending');
        $done = array_slice(array_filter($s['subs'], fn($x) => $x['status'] !== 'pending'), -self::MAX_DONE);
        $s['subs'] = $pending + $done; // keyed by id
        self::write("$this->stateDir/store.json", $s, 0600);
    }

    private function saveCatalog(array $c): void
    {
        $c['updated'] = time();
        self::write("$this->dir/catalog.json", $c, 0644); // readable by every assistant
    }

    /** What an outbox item says, as a hash: the same thing sent twice is reviewed once. */
    public static function hash(array $item): string
    {
        return substr(sha1(json_encode($item['package'] ?? $item['recipe'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /** An app package, checked like an app the assistant wrote; its connectors only say how to install them. */
    public static function cleanApp(array $pkg): array
    {
        if (($pkg['format'] ?? '') !== Apps::PACKAGE) throw new \InvalidArgumentException('This is not a Talixio app (.talixio-app.json).');
        $name = trim(preg_replace('/\s+/', ' ', (string)($pkg['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 60) throw new \InvalidArgumentException('name: 1 to 60 characters');
        $html = (string)($pkg['html'] ?? ''); $script = (string)($pkg['script'] ?? '');
        if (strlen($html) + strlen($script) > 200_000) throw new \InvalidArgumentException('html and script: 200 KB at most');
        if ($e = Apps::checkCode($html, $script)) throw new \InvalidArgumentException($e);
        $sources = [];
        foreach (array_slice((array)($pkg['sources'] ?? []), 0, 10) as $s) {
            $s = (array)$s;
            $path = ($s['type'] ?? '') === 'file' ? Library::normalize(dirname((string)($s['path'] ?? ''))) . '/' . basename((string)($s['path'] ?? '')) : null;
            $c = Apps::checkSources([($path !== null ? ['file' => 1] : []) + $s])[0]; // a file is found by its place in each library
            if ($path !== null) { unset($c['file']); $c['path'] = $path; }
            $sources[] = $c;
        }
        $conn = [];
        foreach ((array)($pkg['connectors'] ?? []) as $n => $r)
            if (($n = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$n))) !== '') $conn[$n] = Apps::cleanRecipe((array)$r);
        return ['format' => Apps::PACKAGE, 'version' => 1, 'name' => $name, 'description' => mb_substr(trim((string)($pkg['description'] ?? '')), 0, 300),
            'icon' => mb_substr(trim((string)($pkg['icon'] ?? '')), 0, 4), 'html' => $html, 'script' => $script, 'sources' => $sources, 'connectors' => $conn];
    }

    /** A connector: its name, what it does, and how to install it (a GitHub repository, or an https address and header names). */
    public static function cleanConnector(array $r): array
    {
        $name = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)($r['name'] ?? ''))));
        $name = trim((string)$name, '_');
        if ($name === '' || strlen($name) > 40) throw new \InvalidArgumentException('name: letters and digits, 40 at most');
        $c = Apps::cleanRecipe($r);
        if (!empty($c['manual'])) throw new \InvalidArgumentException('A connector of the store installs from a GitHub repository or an https address.');
        return ['name' => $name] + $c;
    }

    /**
     * Takes what one assistant has in its outbox: each new thing waits for review. A newer version of something still
     * waiting replaces it; something already decided is not asked again.
     * @param array $items as `talixio store-outbox` prints them
     */
    public function collect(string $instance, string $who, array $items): void
    {
        $s = $this->state(); $changed = false;
        foreach ($items as $it) {
            $it = (array)$it;
            $key = (string)($it['key'] ?? ''); $kind = (string)($it['kind'] ?? '');
            if (!preg_match('/^(app|mcp)-[\w-]{1,60}$/', $key) || !in_array($kind, ['app', 'connector'], true) || ($it['status'] ?? '') !== 'sent') continue;
            $hash = self::hash($it);
            $id = substr(sha1("$instance\n$key\n$hash"), 0, 12);
            if (isset($s['subs'][$id])) continue;
            foreach ($s['subs'] as $k => $x) if ($x['instance'] === $instance && $x['key'] === $key && $x['status'] === 'pending') unset($s['subs'][$k]);
            try {
                $clean = $kind === 'app' ? self::cleanApp((array)($it['package'] ?? [])) : self::cleanConnector((array)($it['recipe'] ?? []));
                $error = null;
            } catch (\InvalidArgumentException $e) { $clean = null; $error = $e->getMessage(); }
            $raw = (array)($it['package'] ?? $it['recipe'] ?? []);
            $s['subs'][$id] = ['id' => $id, 'instance' => $instance, 'who' => mb_substr($who, 0, 80), 'key' => $key, 'hash' => $hash, 'kind' => $kind,
                'name' => mb_substr((string)($clean['name'] ?? $raw['name'] ?? $key), 0, 60), 'note' => mb_substr(trim((string)($it['note'] ?? '')), 0, 500),
                'item' => $clean, 'error' => $error, 'replaces' => $s['links']["$instance:$key"] ?? null, 'at' => (int)($it['at'] ?? time()), 'status' => 'pending'];
            $changed = true;
        }
        if ($changed) $this->saveState($s);
    }

    /** @return array the decided submission (instance, key and hash: to tell that assistant) */
    public function decide(string $id, bool $approve, string $note = ''): array
    {
        $s = $this->state();
        $sub = $s['subs'][$id] ?? throw new \InvalidArgumentException('Nothing waits for review under this number.');
        if ($sub['status'] !== 'pending') throw new \InvalidArgumentException('Already decided.');
        if ($approve) {
            if (!$sub['item']) throw new \InvalidArgumentException('This one cannot be published: ' . $sub['error']);
            $src = $sub['instance'] . ':' . $sub['key'];
            $sub['published'] = $this->publish($sub['kind'], $sub['item'], $s['links'][$src] ?? null);
            $s['links'][$src] = $sub['published'];
        }
        $sub['status'] = $approve ? 'approved' : 'rejected';
        $sub['reason'] = mb_substr(trim($note), 0, 300);
        $sub['decided'] = time();
        $sub['item'] = null; // the published copy is in the catalog
        unset($s['subs'][$id]); $s['subs'][$id] = $sub;
        $this->saveState($s);
        return $sub;
    }

    /** Puts an item in the catalog, as a new one or over the one it replaces (its version goes up). @return string its id */
    private function publish(string $kind, array $item, ?string $replace = null): string
    {
        $c = $this->read(); $list = $kind === 'app' ? 'apps' : 'connectors';
        $entry = $kind === 'app'
            ? ['name' => $item['name'], 'description' => $item['description'], 'icon' => $item['icon'], 'connectors' => array_keys($item['connectors']), 'package' => $item]
            : $item;
        foreach ($c[$list] as $i => $x) if ($replace !== null && $x['id'] === $replace) {
            $c[$list][$i] = ['id' => $replace, 'version' => (int)$x['version'] + 1, 'at' => time()] + $entry;
            $this->saveCatalog($c);
            return $replace;
        }
        $id = ($kind === 'app' ? 'app-' : 'mcp-') . bin2hex(random_bytes(5));
        $c[$list][] = ['id' => $id, 'version' => 1, 'at' => time()] + $entry;
        $this->saveCatalog($c);
        return $id;
    }

    /** Added by the admin: published at once. */
    public function add(string $kind, array $data): string
    {
        return $this->publish($kind, $kind === 'app' ? self::cleanApp($data) : self::cleanConnector($data));
    }

    /**
     * The connectors the FAQ lists ("▶ Install" links of "Connector list.md"), as store connectors: label, what it does,
     * and its GitHub repository or address (with the header names each person fills in).
     */
    public static function faqConnectors(string $md): array
    {
        $out = [];
        foreach (preg_split('/^- /m', $md) as $item) {
            $item = preg_split('/\n\s*\n|\n#/', $item)[0]; // up to the end of the list item
            if (!preg_match('/\[▶ Install\]\(\?connector=([^)\s]+)\)/u', $item, $m)) continue;
            parse_str('connector=' . $m[1], $q);
            $src = (string)$q['connector'];
            $label = preg_match('/^\*\*(.+?)\*\*/', $item, $l) ? trim(preg_replace('/\s*\(.*?\)/', '', $l[1])) : '';
            $name = (string)($q['name'] ?? '') ?: (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $label !== '' ? $label : basename($src));
            $desc = preg_replace('/\[▶ Install\]\([^)]*\)/u', '', substr($item, strlen($l[0] ?? '')));
            $desc = trim(preg_replace('/\s+/', ' ', str_replace(['**', '`', '*'], '', ltrim($desc, ': '))));
            $r = ['name' => $name, 'description' => rtrim(($label !== '' ? "$label: " : '') . $desc, ': '),
                (preg_match('#^https://(www\.)?github\.com/#i', $src) ? 'github' : 'url') => $src,
                'headers' => array_filter(explode(',', (string)($q['headers'] ?? '')))];
            try { $out[] = self::cleanConnector($r); } catch (\InvalidArgumentException) {}
        }
        return $out;
    }

    /**
     * Adds the FAQ's connectors the store does not have yet (same repository or address). $once (at each start of the
     * agent): only those it never offered before, so one the admin removed does not come back. @return int how many
     */
    public function addFaq(string $md, bool $once = false): int
    {
        $c = $this->read(); $s = $this->state(); $seen = array_flip((array)($s['faq'] ?? [])); $have = [];
        foreach ($c['connectors'] as $x) $have[strtolower((string)($x['github'] ?? $x['url'] ?? ''))] = 1;
        $n = 0;
        foreach (self::faqConnectors($md) as $r) {
            $k = strtolower((string)($r['github'] ?? $r['url'] ?? ''));
            if (isset($have[$k]) || ($once && isset($seen[$k]))) { $seen[$k] = 1; continue; }
            $have[$k] = $seen[$k] = 1;
            $c['connectors'][] = ['id' => 'mcp-' . bin2hex(random_bytes(5)), 'version' => 1, 'at' => time()] + $r;
            $n++;
        }
        if ($n) $this->saveCatalog($c);
        if (count($seen) !== count((array)($s['faq'] ?? []))) { $s['faq'] = array_keys($seen); $this->saveState($s); }
        return $n;
    }

    /** Out of the store: assistants that installed it keep their copy. */
    public function remove(string $id): void
    {
        $c = $this->read(); $n = count($c['apps']) + count($c['connectors']);
        foreach (['apps', 'connectors'] as $l) $c[$l] = array_values(array_filter($c[$l], fn($x) => $x['id'] !== $id));
        if (count($c['apps']) + count($c['connectors']) === $n) throw new \InvalidArgumentException('Not in the store.');
        $this->saveCatalog($c);
        $s = $this->state(); $s['links'] = array_filter($s['links'], fn($v) => $v !== $id); $this->saveState($s);
    }
}
