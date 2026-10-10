<?php
declare(strict_types=1);
namespace Talixio;

/**
 * A local MCP connector installed from the library: the person uploads the server's folder as a .zip (e.g. in
 * /Connecteurs), picks it in Settings → Connectors (or "Install as a connector" on the file), fills in what it
 * needs (URL, account...), and it is unpacked in data/connectors/<name>, its Node dependencies installed, and
 * added to the connectors. No SSH, no administrator. It runs like any connector the person could already declare.
 */
final class ConnectorPackage
{
    private const MAX_BYTES = 80 << 20; // unpacked
    private const MAX_FILES = 20000;
    private const SKIP_ENV = ['NODE_ENV', 'HOME', 'PATH', 'PWD', 'DEBUG', 'TERM', 'LANG', 'TZ', 'PORT', 'HOST', 'CI', 'NODE_OPTIONS', 'TMPDIR', 'USER', 'SHELL'];

    public function __construct(private App $app) {}

    public function dir(): string { return $this->app->cfg->dataDir . '/connectors'; }

    /** The .zip files of the library (candidates). */
    public function packages(): array
    {
        $rows = $this->app->store->q("SELECT id, name, folder, size, created FROM files WHERE lower(name) LIKE '%.zip' ORDER BY (folder LIKE '/Connect%') DESC, created DESC LIMIT 200")->fetchAll();
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'folder' => $r['folder'], 'size' => (int)$r['size']], $rows);
    }

    public static function slug(string $s): string
    {
        $s = strtolower(preg_replace('/\.zip$/i', '', basename($s)));
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s), '-');
        return substr($s, 0, 40) ?: 'connector';
    }

    private function zip(int $fileId): array
    {
        $f = $this->app->files()->get($fileId) ?? throw new \InvalidArgumentException('No such file in the library.');
        if (!preg_match('/\.zip$/i', $f['name'])) throw new \InvalidArgumentException('A connector is installed from a .zip of its folder.');
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('php-zip is missing (sudo ./install.sh again).');
        $z = new \ZipArchive();
        if ($z->open($f['path']) !== true) throw new \InvalidArgumentException('This .zip cannot be opened.');
        // the files, without the folder they were zipped in (mcp/src/server.js → src/server.js)
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = str_replace('\\', '/', (string)$z->getNameIndex($i));
            if ($n === '' || str_starts_with($n, '__MACOSX/') || str_ends_with($n, '/')) continue;
            if (str_starts_with($n, '/') || preg_match('#(^|/)\.\.(/|$)#', $n)) continue; // nothing outside its folder
            $names[$i] = $n;
        }
        if (!$names) throw new \InvalidArgumentException('This .zip is empty.');
        $first = array_map(fn($n) => explode('/', $n)[0], $names);
        $prefix = count(array_unique($first)) === 1 && array_filter($names, fn($n) => str_contains($n, '/')) === $names ? $first[array_key_first($first)] . '/' : '';
        $files = [];
        foreach ($names as $i => $n) $files[$i] = substr($n, strlen($prefix));
        return [$f, $z, $files];
    }

    /** What the .zip holds: how to start it, which settings it reads, whether it has dependencies. */
    public function inspect(int $fileId): array
    {
        [$f, $z, $files] = $this->zip($fileId);
        $read = fn(string $rel) => ($i = array_search($rel, $files, true)) !== false ? (string)$z->getFromIndex($i) : null;
        $pkg = json_decode((string)$read('package.json'), true);
        $has = fn(string $rel) => in_array($rel, $files, true);
        $entry = null; $runtime = 'node';
        if (is_array($pkg)) {
            $bin = $pkg['bin'] ?? null;
            foreach ([is_string($bin) ? $bin : (is_array($bin) ? reset($bin) : null), $pkg['main'] ?? null] as $c) {
                $c = $c ? ltrim(preg_replace('#^\./#', '', (string)$c), '/') : null;
                if ($c && $has($c)) { $entry = $c; break; }
            }
        }
        foreach (['src/server.js', 'server.js', 'index.js', 'src/index.js', 'dist/index.js', 'build/index.js', 'server.mjs', 'index.mjs'] as $c) if (!$entry && $has($c)) $entry = $c;
        foreach (['server.py', 'main.py', 'src/server.py', '__main__.py'] as $c) if (!$entry && $has($c)) { $entry = $c; $runtime = 'python'; }
        $env = [];
        $text = '';
        foreach ($files as $i => $n) {
            if (str_contains($n, 'node_modules/') || !preg_match('/\.(m?js|cjs|ts|py)$|(^|\/)\.env\.(example|sample)$|^README/i', $n)) continue;
            if (strlen($text) > 4 << 20) break;
            $text .= "\n" . $z->getFromIndex($i);
        }
        preg_match_all('/process\.env(?:\.([A-Z][A-Z0-9_]{2,})|\[\s*[\'"]([A-Z][A-Z0-9_]{2,})[\'"]\s*\])|os\.(?:environ(?:\.get)?\s*[\[(]\s*|getenv\s*\(\s*)[\'"]([A-Z][A-Z0-9_]{2,})[\'"]|^\s*([A-Z][A-Z0-9_]{2,})=/m', $text, $m, PREG_SET_ORDER);
        foreach ($m as $x) { $k = $x[1] ?: ($x[2] ?? '') ?: ($x[3] ?? '') ?: ($x[4] ?? ''); if ($k !== '' && !in_array($k, self::SKIP_ENV, true)) $env[$k] = true; }
        $z->close();
        $name = self::slug(is_array($pkg) && !empty($pkg['name']) ? preg_replace('#^@[^/]+/#', '', (string)$pkg['name']) : $f['name']);
        $current = $this->app->mcp()->servers()[str_replace('-', '_', $name)] ?? null;
        return ['file' => $fileId, 'file_name' => $f['name'], 'name' => $name, 'runtime' => $runtime, 'entry' => $entry,
            'deps' => is_array($pkg) && !empty($pkg['dependencies']) && !in_array('node_modules/', array_map(fn($n) => substr($n, 0, 13), $files), true),
            'build' => is_array($pkg) && !$entry && !empty($pkg['scripts']['build']),
            'env' => array_keys($env), 'values' => $current ? array_map(fn($v) => preg_match('/PASS|SECRET|TOKEN|KEY/i', (string)$v) ? '' : (string)$v, (array)($current['env'] ?? [])) : [],
            'installed' => (bool)$current, 'files' => count($files)];
    }

    /** Unpack, then finish in the background (dependencies, connector added, tools listed). */
    public function install(int $fileId, string $name, array $env, bool $background = true): array
    {
        $name = self::slug($name);
        $info = $this->inspect($fileId);
        if (!$info['entry'] && !$info['build']) throw new \InvalidArgumentException('No server found in this .zip (package.json with "bin" or "main", src/server.js, server.js, index.js or server.py).');
        if ($info['runtime'] === 'node' && trim((string)shell_exec('command -v node 2>/dev/null')) === '') throw new \RuntimeException('Node.js is not installed on this server (sudo ./install.sh --with-node; it is in the Docker image).');
        $clean = [];
        $old = $this->app->mcp()->servers()[str_replace('-', '_', $name)]['env'] ?? [];
        foreach ($env as $k => $v) {
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,60}$/', (string)$k)) continue;
            $v = trim((string)$v);
            if ($v === '' && isset($old[$k])) $v = (string)$old[$k]; // a secret left empty keeps its value
            if ($v !== '') $clean[$k] = mb_substr($v, 0, 2000);
        }
        // unpack into a new folder, then swap (the previous version stays until it works)
        [, $z, $files] = $this->zip($fileId);
        $base = $this->dir(); if (!is_dir($base)) mkdir($base, 0700, true);
        $tmp = "$base/.$name-" . bin2hex(random_bytes(4));
        mkdir($tmp, 0700);
        $bytes = 0; $count = 0;
        try {
            foreach ($files as $i => $rel) {
                if ($rel === '' || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel)) continue; // nothing outside its folder
                $st = $z->statIndex($i);
                if (++$count > self::MAX_FILES || ($bytes += (int)$st['size']) > self::MAX_BYTES) throw new \InvalidArgumentException('This .zip is too big for a connector (80 MB, 20 000 files).');
                $attr = 0; $z->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) continue; // no symbolic links
                $to = "$tmp/$rel";
                if (!is_dir(dirname($to))) mkdir(dirname($to), 0700, true);
                $in = $z->getStream((string)$z->getNameIndex($i));
                $out = fopen($to, 'wb'); stream_copy_to_stream($in, $out); fclose($out); fclose($in);
                if ($opsys === \ZipArchive::OPSYS_UNIX && ($attr >> 16) & 0100) chmod($to, 0700);
            }
        } catch (\Throwable $e) {
            $z->close(); exec('rm -rf ' . escapeshellarg($tmp));
            throw $e;
        }
        $z->close();
        $state = ['name' => $name, 'state' => 'running', 'step' => 'unpacked', 'ts' => time(), 'tmp' => basename($tmp), 'file' => $fileId,
            'runtime' => $info['runtime'], 'entry' => $info['entry'], 'deps' => $info['deps'], 'build' => $info['build'], 'env' => $clean];
        $this->setState($state);
        if ($background) Background::start([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'connector-install'], $this->app->cfg->dataDir . '/connectors/install.log');
        Log::info("connector $name: installing from library file #$fileId");
        return ['ok' => true, 'name' => $name];
    }

    private function setState(array $s): void { $this->app->store->set('connector_install', json_encode($s + ['ts' => time()], JSON_UNESCAPED_SLASHES)); }

    /** For the web app: no env values (they may be secrets). */
    public function status(): array
    {
        $s = json_decode($this->app->store->get('connector_install', ''), true) ?: ['state' => 'none'];
        unset($s['env'], $s['tmp']);
        return $s;
    }

    /** The background part (talixio connector-install). */
    public function finish(): void
    {
        $s = json_decode($this->app->store->get('connector_install', ''), true) ?: [];
        if (($s['state'] ?? '') !== 'running') return;
        $base = $this->dir(); $tmp = "$base/" . $s['tmp']; $name = $s['name']; $final = "$base/$name";
        $run = function (string $cmd, string $step) use (&$s, $tmp): void {
            $s['step'] = $step; $this->setState($s);
            exec('cd ' . escapeshellarg($tmp) . " && $cmd 2>&1", $out, $code);
            if ($code !== 0) throw new \RuntimeException("$step: " . mb_substr(trim(implode("\n", array_slice($out, -12))), -1200));
        };
        try {
            $npm = 'npm --no-audit --no-fund --loglevel=error';
            if ($s['build']) { $run("$npm install", 'installing dependencies'); $run('npm run build', 'building'); }
            elseif ($s['deps']) $run("$npm install --omit=dev", 'installing dependencies');
            $entry = $s['entry'];
            if (!$entry) foreach (['dist/index.js', 'build/index.js', 'dist/server.js'] as $c) if (is_file("$tmp/$c")) { $entry = $c; break; }
            if (!$entry || !is_file("$tmp/$entry")) throw new \RuntimeException('no server file after building');
            // swap the folders, then declare it
            if (is_dir($final)) exec('rm -rf ' . escapeshellarg($final));
            rename($tmp, $final);
            $mcp = $this->app->mcp();
            $raw = json_decode($mcp->raw(), true) ?: ['mcpServers' => []];
            // paths relative to the data folder: they still hold after a move (install.sh → Docker, a transfer)
            $raw['mcpServers'][$name] = ['command' => $s['runtime'] === 'python' ? 'python3' : 'node', 'args' => [$entry], 'cwd' => "connectors/$name"]
                + ($s['env'] ? ['env' => $s['env']] : []) + ['source' => ['library_file' => (int)$s['file'], 'installed' => date('c')]]
                + array_intersect_key((array)($raw['mcpServers'][$name] ?? []), ['trust' => 1]);
            $mcp->saveRaw(json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $s['step'] = 'connecting'; $this->setState($s);
            $cache = $mcp->refresh();
            $key = preg_replace('/[^a-z0-9]+/', '_', $name);
            $err = $cache[$key]['error'] ?? null;
            $s['state'] = $err ? 'failed' : 'done';
            $s['step'] = $err ? 'connecting' : 'ready';
            $s['tools'] = count($cache[$key]['tools'] ?? []);
            if ($err) $s['error'] = 'Installed, but the server did not answer: ' . mb_substr($err, 0, 600);
            Log::info("connector $name: " . ($err ? "installed, not answering ($err)" : "ready, {$s['tools']} tool(s)"));
        } catch (\Throwable $e) {
            if (is_dir($tmp)) exec('rm -rf ' . escapeshellarg($tmp));
            $s['state'] = 'failed'; $s['error'] = $e->getMessage();
            Log::warn("connector $name: {$e->getMessage()}");
        }
        unset($s['tmp']);
        $this->setState($s);
    }
}
