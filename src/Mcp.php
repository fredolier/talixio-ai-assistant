<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Connectors: tools from MCP servers (GitHub, Notion, home automation, your own scripts...).
 * data/mcp.json uses the usual format:
 *   {"mcpServers": {"github": {"command": "npx", "args": ["-y", "@modelcontextprotocol/server-github"], "env": {"GITHUB_TOKEN": "..."}},
 *                   "notes":  {"url": "https://example.com/mcp", "headers": {"Authorization": "Bearer ..."}, "trust": "all"},
 *                   "notion": {"url": "https://mcp.notion.com/mcp"}}}          (OAuth: "Sign in" in Settings, see McpOAuth)
 * Local servers (command) are started for each call, in the sandbox (bubblewrap) when it is installed: they see the
 * system programs, their own folder and the files named on their command line, not the rest of the server or its data
 * ("sandbox": false lets one out, never on a shared server). Remote ones (url) use Streamable HTTP. Tool lists are
 * cached (refreshed by the worker, or from Settings). Every tool waits for your approval unless you marked it as only
 * reading ("read_only": ["list_tasks", ...], from Settings) or the server has "trust": "all"; what a server says about
 * its own tools is only shown as a hint. Tools are named mcp_<server>_<tool>.
 */
final class Mcp
{
    public const PROTOCOL = '2025-06-18';

    public function __construct(private App $app) {}

    public function file(): string { return $this->app->cfg->dataDir . '/mcp.json'; }
    private function cacheFile(): string { return $this->app->cfg->dataDir . '/mcp-cache.json'; }

    /** @return array<string,array> name => server config */
    public function servers(): array
    {
        $d = json_decode((string)@file_get_contents($this->file()), true);
        $out = [];
        foreach ((array)($d['mcpServers'] ?? []) as $name => $s) {
            $name = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$name));
            if ($name !== '' && is_array($s) && (isset($s['command']) || isset($s['url'])) && empty($s['disabled'])) $out[$name] = $s + ['_name' => $name];
        }
        return $out;
    }

    public function raw(): string
    {
        return (string)(@file_get_contents($this->file()) ?: "{\n  \"mcpServers\": {}\n}\n");
    }

    /** Validate and save the configuration (from Settings). */
    public function saveRaw(string $json): void
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !is_array($d['mcpServers'] ?? null)) throw new \InvalidArgumentException('Expected JSON like {"mcpServers": {"name": {"command": "...", "args": []}}}');
        foreach ($d['mcpServers'] as $n => $s) {
            if (!is_array($s) || (!isset($s['command']) && !isset($s['url']))) throw new \InvalidArgumentException("server \"$n\": give a command (local) or a url (remote)");
            if (isset($s['url']) && !preg_match('#^https?://#i', (string)$s['url'])) throw new \InvalidArgumentException("server \"$n\": the url must start with http(s)://");
        }
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    public function cache(): array { return json_decode((string)@file_get_contents($this->cacheFile()), true) ?: []; }

    /** Ask every server for its tools and keep the lists. Returns the cache (with an error per failing server). */
    public function refresh(): array
    {
        $cache = [];
        foreach ($this->servers() as $name => $s) {
            try {
                $tools = [];
                $cursor = null;
                for ($page = 0; $page < 10; $page++) {
                    $r = $this->request($s, 'tools/list', $cursor ? ['cursor' => $cursor] : new \stdClass(), 120);
                    $tools = array_merge($tools, (array)($r['tools'] ?? []));
                    if (!($cursor = $r['nextCursor'] ?? null)) break;
                }
                $cache[$name] = ['tools' => array_values(array_filter($tools, fn($t) => is_array($t) && isset($t['name']))), 'error' => null, 'at' => time()];
            } catch (McpAuthRequired $e) {
                $cache[$name] = ['tools' => [], 'error' => $e->getMessage(), 'at' => time(), 'auth' => true, 'auth_meta' => $e->metadata];
            } catch (\Throwable $e) {
                $cache[$name] = ['tools' => [], 'error' => $e->getMessage(), 'at' => time()];
                Log::warn("mcp $name: {$e->getMessage()}");
            }
        }
        file_put_contents($this->cacheFile(), json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $cache;
    }

    /** Add the cached tools to the assistant's tools. */
    public function register(Tools $tools): void
    {
        $servers = $this->servers();
        foreach ($this->cache() as $server => $info) {
            if (!isset($servers[$server])) continue;
            $trustAll = ($servers[$server]['trust'] ?? '') === 'all';
            $reads = array_map('strval', (array)($servers[$server]['read_only'] ?? []));
            foreach ($info['tools'] ?? [] as $t) {
                $name = self::toolName($server, (string)$t['name']);
                $schema = is_array($t['inputSchema'] ?? null) ? $t['inputSchema'] : ['type' => 'object'];
                $remote = (string)$t['name'];
                $readOnly = in_array($remote, $reads, true);
                $tools->register($name, "[$server] " . mb_substr(trim((string)($t['description'] ?? $t['title'] ?? $remote)), 0, 1500),
                    (array)($schema['properties'] ?? []), array_values((array)($schema['required'] ?? [])),
                    fn(array $a) => $this->call($server, $remote, $a),
                    $readOnly || $trustAll ? null : fn(array $a) => "Use \"$remote\" on $server: " . mb_substr(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1500));
                if ($readOnly) $tools->readOnly[] = $name;
            }
        }
    }

    /** The server says the tool only reads (readOnlyHint): shown as a hint in Settings, never trusted by itself. */
    public static function saysReadOnly(array $t): bool
    {
        return !empty($t['annotations']['readOnlyHint']) && empty($t['annotations']['destructiveHint']);
    }

    /** Mark a tool as only reading (runs without asking, offered to background jobs), or not any more. */
    public function setReadOnly(string $server, string $tool, bool $on): void
    {
        $d = json_decode($this->raw(), true);
        foreach ((array)($d['mcpServers'] ?? []) as $key => $s) {
            if (preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$key)) !== $server || !is_array($s)) continue;
            $reads = array_values(array_diff(array_map('strval', (array)($s['read_only'] ?? [])), [$tool]));
            if ($on) $reads[] = $tool;
            if ($reads) $d['mcpServers'][$key]['read_only'] = $reads; else unset($d['mcpServers'][$key]['read_only']);
            $this->saveRaw(json_encode($d));
            return;
        }
        throw new \InvalidArgumentException("no connector $server");
    }

    /** Runs in the sandbox: always on a shared server, otherwise when bubblewrap is installed, unless "sandbox": false. */
    public static function sandboxed(array $s): bool
    {
        if (isset($s['url'])) return false;
        $can = Shell::binary() !== null;
        if (Config::inFleet() && !$can) throw new \RuntimeException('local connectors need the sandbox (bubblewrap) on a shared server');
        return $can && (Config::inFleet() || ($s['sandbox'] ?? true) !== false);
    }

    public static function toolName(string $server, string $tool): string
    {
        return substr('mcp_' . $server . '_' . trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $tool), '_'), 0, 64);
    }

    /** Call a tool; returns its text (images are left out, errors start with "error:"). */
    public function call(string $server, string $tool, array $args): string
    {
        $s = $this->servers()[$server] ?? throw new \RuntimeException("connector $server is not configured any more");
        $r = Privacy::as('connector', fn() => $this->request($s, 'tools/call', ['name' => $tool, 'arguments' => (object)$args], 120));
        $parts = [];
        foreach ((array)($r['content'] ?? []) as $c) {
            $parts[] = match ($c['type'] ?? '') {
                'text' => (string)$c['text'],
                'resource' => (string)($c['resource']['text'] ?? '[resource ' . ($c['resource']['uri'] ?? '') . ']'),
                'resource_link' => '[link ' . ($c['uri'] ?? '') . ']',
                'image' => '[image not shown]',
                default => '',
            };
        }
        if (!$parts && isset($r['structuredContent'])) $parts[] = json_encode($r['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = mb_substr(implode("\n", array_filter($parts, 'strlen')), 0, 40000);
        return (!empty($r['isError']) ? 'error: ' : '') . ($text !== '' ? $text : '(empty result)');
    }

    /** One JSON-RPC request in a fresh session: initialize, notifications/initialized, then the request. */
    public function request(array $s, string $method, array|object $params, int $timeout = 60): array
    {
        if ($method !== 'tools/call') return Privacy::approved(fn() => Privacy::as('connector', fn() => isset($s['url']) ? $this->http($s, $method, $params, $timeout) : $this->stdio($s, $method, $params, $timeout))); // listing tools carries nothing of yours
        return isset($s['url']) ? $this->http($s, $method, $params, $timeout) : $this->stdio($s, $method, $params, $timeout);
    }

    private static function initialize(): array
    {
        return ['protocolVersion' => self::PROTOCOL, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'talixio', 'version' => '1.0']];
    }

    /** A connector installed before its browser was (Playwright, Patchright): downloaded now, tried again at most hourly. */
    private function browserFor(string $cwd): void
    {
        $cmds = ConnectorPackage::browserInstalls($cwd, $this->app->cfg->dataDir);
        if (!$cmds) return;
        $failed = ConnectorPackage::browsers($this->app->cfg->dataDir) . '/.failed-' . md5($cwd);
        if (is_file($failed) && filemtime($failed) > time() - 3600) throw new \RuntimeException('The web browser this connector drives could not be downloaded: ' . file_get_contents($failed));
        Log::info("connector in $cwd: downloading the web browser it drives");
        foreach ($cmds as $c) {
            $out = [];
            exec('cd ' . escapeshellarg($cwd) . " && $c 2>&1", $out, $code);
            if ($code !== 0) {
                $why = mb_substr(trim(implode("\n", array_slice($out, -6))), -600);
                @mkdir(dirname($failed), 0700, true); file_put_contents($failed, $why);
                throw new \RuntimeException("The web browser this connector drives could not be downloaded (tried again in an hour): $why");
            }
        }
        @unlink($failed);
    }

    /** A local server's own home folder (its caches, its settings), the only place it keeps things besides its own folder. */
    private function homeOf(array $s): string
    {
        $d = $this->app->cfg->dataDir . '/connectors-home/' . ($s['_name'] ?? 'server');
        if (!is_dir($d)) mkdir($d, 0700, true);
        return $d;
    }

    /**
     * The bubblewrap command line for a local server: system programs read-only, network on (connectors talk to
     * services), its folder and home writable, the files named on its command line read-only; the rest of the
     * data folder (keys, mail, documents) out of sight.
     */
    public function sandboxArgv(array $cmd, string $cwd, string $home, array $env, bool $browsers = false): array
    {
        $a = [Shell::binary(), '--die-with-parent', '--new-session', '--unshare-all', '--share-net',
            '--ro-bind', '/usr', '/usr', '--ro-bind-try', '/opt', '/opt', '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/dev/shm', '--tmpfs', '/tmp'];
        foreach (['bin', 'lib', 'lib64', 'sbin'] as $l) if (is_link("/$l")) array_push($a, '--symlink', "usr/$l", "/$l");
        foreach (['/etc/alternatives', '/etc/ssl', '/etc/ca-certificates', '/etc/fonts', '/etc/localtime', '/etc/mime.types', '/etc/passwd', '/etc/group',
                  '/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf', '/etc/gai.conf', '/etc/php', '/etc/python3'] as $e) array_push($a, '--ro-bind-try', $e, $e);
        $data = rtrim($this->app->cfg->dataDir, '/') . '/';
        $writable = array_unique([$home, $cwd]);
        foreach ($writable as $d) if (!str_starts_with($data, rtrim($d, '/') . '/')) array_push($a, '--bind', $d, $d); // never a folder holding the data folder
        if ($browsers) array_push($a, '--ro-bind-try', ConnectorPackage::browsers($this->app->cfg->dataDir), ConnectorPackage::browsers($this->app->cfg->dataDir));
        foreach ($cmd as $part) { // a script given by its path (node /opt/x/server.js): visible, read-only; never something of the data folder
            if ($part === '' || $part[0] !== '/' || !is_file($part) || str_starts_with($part, $data) || str_starts_with($part, '/usr/')) continue;
            array_push($a, '--ro-bind', $part, $part);
        }
        array_push($a, '--chdir', $cwd, '--clearenv');
        foreach ($env as $k => $v) array_push($a, '--setenv', (string)$k, (string)$v);
        return array_merge($a, $cmd);
    }

    private function stdio(array $s, string $method, array|object $params, int $timeout): array
    {
        $sandbox = self::sandboxed($s);
        $home = $sandbox ? $this->homeOf($s) : (getenv('HOME') ?: $this->app->cfg->dataDir);
        $env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home, 'LANG' => 'C.UTF-8'];
        if (isset($s['source']['library_file'])) $env['PLAYWRIGHT_BROWSERS_PATH'] = ConnectorPackage::browsers($this->app->cfg->dataDir);
        foreach ((array)($s['env'] ?? []) as $k => $v) $env[(string)$k] = (string)$v;
        $cmd = array_merge([(string)$s['command']], array_map('strval', (array)($s['args'] ?? [])));
        $err = tempnam(sys_get_temp_dir(), 'mcp');
        $cwd = (string)($s['cwd'] ?? ''); // relative: inside the data folder (connectors installed from the library)
        $cwd = $cwd === '' ? ($sandbox ? $home : $this->app->cfg->dataDir) : ($cwd[0] === '/' ? $cwd : $this->app->cfg->dataDir . '/' . $cwd);
        if (isset($s['source']['library_file']) && $method === 'tools/call') $this->browserFor($cwd);
        if ($sandbox) $cmd = $this->sandboxArgv($cmd, $cwd, $home, $env, isset($s['source']['library_file']));
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes, $cwd, $env);
        if (!is_resource($proc)) throw new \RuntimeException("could not start {$s['command']}");
        try {
            $send = fn(array $m) => fwrite($pipes[0], json_encode(['jsonrpc' => '2.0'] + $m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + $timeout; $buf = '';
            $wait = function (int $id) use ($pipes, &$buf, $deadline, $proc, $err): array {
                while (true) {
                    while (($nl = strpos($buf, "\n")) !== false) {
                        $line = trim(substr($buf, 0, $nl)); $buf = substr($buf, $nl + 1);
                        $m = json_decode($line, true);
                        if (is_array($m) && ($m['id'] ?? null) === $id && !isset($m['method'])) {
                            if (isset($m['error'])) throw new \RuntimeException('server error: ' . ($m['error']['message'] ?? json_encode($m['error'])));
                            return (array)($m['result'] ?? []);
                        }
                    }
                    if (microtime(true) > $deadline) throw new \RuntimeException('no answer in time');
                    $r = [$pipes[1]]; $w = $e = null;
                    if (@stream_select($r, $w, $e, 0, 200000)) {
                        $chunk = fread($pipes[1], 65536);
                        if ($chunk !== '' && $chunk !== false) { $buf .= $chunk; continue; }
                    }
                    if (!proc_get_status($proc)['running'] && feof($pipes[1])) {
                        throw new \RuntimeException('the server stopped: ' . mb_substr(trim((string)file_get_contents($err)), -400));
                    }
                }
            };
            $send(['id' => 1, 'method' => 'initialize', 'params' => self::initialize()]);
            $wait(1);
            $send(['method' => 'notifications/initialized']);
            $send(['id' => 2, 'method' => $method, 'params' => $params]);
            return $wait(2);
        } finally {
            @fclose($pipes[0]); @fclose($pipes[1]);
            proc_terminate($proc); proc_close($proc);
            @unlink($err);
        }
    }

    /** Remote server: with the headers of mcp.json, or the OAuth sign-in (renewed once if refused). */
    private function http(array $s, string $method, array|object $params, int $timeout): array
    {
        $name = (string)($s['_name'] ?? '');
        $explicit = (bool)array_filter(array_keys((array)($s['headers'] ?? [])), fn($k) => strcasecmp((string)$k, 'Authorization') === 0);
        $oauth = $this->app->mcpOAuth();
        $token = !$explicit && $name !== '' ? $oauth->accessToken($name) : null;
        try {
            return $this->httpOnce($s, $method, $params, $timeout, $token);
        } catch (McpAuthRequired $e) {
            if ($explicit) throw new \RuntimeException('the server refused the credentials (HTTP 401): check the headers in mcp.json');
            if ($token !== null && ($fresh = $oauth->accessToken($name, true)) !== null) return $this->httpOnce($s, $method, $params, $timeout, $fresh);
            throw new McpAuthRequired("$name needs you to sign in: Settings → Connectors → Sign in", $e->metadata);
        }
    }

    private function httpOnce(array $s, string $method, array|object $params, int $timeout, ?string $token): array
    {
        $session = null;
        $post = function (array $msg) use ($s, &$session, $timeout, $token): array {
            $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: ' . self::PROTOCOL];
            foreach ((array)($s['headers'] ?? []) as $k => $v) $headers[] = "$k: $v";
            if ($token !== null) $headers[] = "Authorization: Bearer $token";
            if ($session) $headers[] = "Mcp-Session-Id: $session";
            $respHeaders = [];
            Http::check((string)$s['url'], 1000);
            $ch = curl_init((string)$s['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0'] + $msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), CURLOPT_USERAGENT => 'Talixio',
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $respHeaders[strtolower(trim($k))] = trim($v); } return strlen($h); }]);
            $body = (string)curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
            curl_close($ch);
            if ($code === 0) throw new \RuntimeException("cannot reach {$s['url']}: $err");
            if ($code === 401) { // sign-in needed (or the token was refused): where to sign in is in WWW-Authenticate
                preg_match('/resource_metadata="?([^",\s]+)/i', $respHeaders['www-authenticate'] ?? '', $mm);
                throw new McpAuthRequired('sign-in needed', $mm[1] ?? null);
            }
            if ($code === 403) throw new \RuntimeException('access refused (HTTP 403)' . ($token !== null ? ': the account you signed in with lacks the rights for this' : ': check the headers in mcp.json'));
            if ($code >= 400) throw new \RuntimeException("HTTP $code: " . mb_substr(trim($body), 0, 300));
            if (isset($respHeaders['mcp-session-id'])) $session = $respHeaders['mcp-session-id'];
            if (!isset($msg['id'])) return [];
            $messages = [];
            if (str_contains($respHeaders['content-type'] ?? '', 'text/event-stream')) {
                foreach (preg_split('/\r?\n\r?\n/', $body) as $block) {
                    $data = '';
                    foreach (preg_split('/\r?\n/', $block) as $line) if (str_starts_with($line, 'data:')) $data .= ltrim(substr($line, 5));
                    if ($data !== '' && is_array($m = json_decode($data, true))) $messages[] = $m;
                }
            } else {
                $m = json_decode($body, true);
                $messages = is_array($m) && array_is_list($m) ? $m : [$m];
            }
            foreach ($messages as $m) {
                if (is_array($m) && ($m['id'] ?? null) === $msg['id'] && !isset($m['method'])) {
                    if (isset($m['error'])) throw new \RuntimeException('server error: ' . ($m['error']['message'] ?? json_encode($m['error'])));
                    return (array)($m['result'] ?? []);
                }
            }
            throw new \RuntimeException('unexpected answer: ' . mb_substr(trim($body), 0, 300));
        };
        $post(['id' => 1, 'method' => 'initialize', 'params' => self::initialize()]);
        $post(['method' => 'notifications/initialized']);
        return $post(['id' => 2, 'method' => $method, 'params' => $params]);
    }
}
