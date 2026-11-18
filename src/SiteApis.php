<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Connectors learned from a website (1.37.0). While the assistant browses a site, the browser notes the JSON calls the
 * site's own pages make (Browser::apiCalls, cleaned by scrub(): no cookies, tokens or keys). The model describes them
 * as a declarative spec (never code), the user approves it, and this class serves it: one tool per endpoint, named
 * like any connector's (mcp_<name>_<endpoint>, so they are grouped, focused and treated as untrusted the same way).
 *
 * A spec: {"name": "shop", "title": "Test Shop", "base": "https://shop.example/api",
 *          "auth": {"type": "none" | "cookies" | "header" | "query", "name": "Authorization", "prefix": "Bearer", "help": "…"},
 *          "endpoints": [{"name": "search_products", "description": "…", "method": "GET", "path": "/products/{id}",
 *                         "params": {"id": {"type": "string", "in": "path", "required": true, "description": "…"}},
 *                         "fixed": {"query": {…}, "body": {…}, "headers": {…}}, "changes": false}]}
 * Every call goes to the base's host only. GET endpoints only read (they run without asking) unless marked "changes";
 * the others ask the user each time. "cookies": the login the user did in the assistant's browser, read at each call
 * (log in again there when it expires). "header"/"query": a key the user enters in Settings, kept sealed, never in chat.
 */
final class SiteApis
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    public const AUTH = ['none', 'cookies', 'header', 'query'];
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];
    /** Headers a spec may never set: the transport's own, and the sign-in ones (those come from auth only). */
    private const RESERVED_HEADERS = ['host', 'cookie', 'authorization', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'proxy-authorization', 'origin', 'referer'];

    /** Tests: fn(string $url): string the Cookie header for that address (null: the assistant's browser). */
    public static $cookies = null;

    public function __construct(private App $app) {}

    private function file(): string { return $this->app->cfg->dataDir . '/site-apis.json'; }

    /** @return array<string,array> name => spec (+ key, sealed; added, at) */
    public function all(): array
    {
        $d = JsonFile::read($this->file(), false);
        return is_array($d['apis'] ?? null) ? $d['apis'] : [];
    }

    public function get(string $name): ?array { return $this->all()[$name] ?? null; }

    /** For Settings: what each learned connector does, without its key. */
    public function list(): array
    {
        $out = [];
        foreach ($this->all() as $name => $s) {
            $out[] = ['name' => $name, 'title' => (string)($s['title'] ?? $name), 'host' => self::host((string)$s['base']), 'base' => (string)$s['base'],
                'auth' => (string)$s['auth']['type'], 'help' => (string)($s['auth']['help'] ?? ''), 'has_key' => ((string)($s['key'] ?? '')) !== '',
                'at' => (int)($s['at'] ?? 0),
                'endpoints' => array_map(fn($e) => ['name' => $e['name'], 'method' => $e['method'], 'path' => $e['path'], 'description' => $e['description'], 'reads' => self::reads($e)], $s['endpoints'])];
        }
        return $out;
    }

    public static function host(string $base): string
    {
        $h = strtolower((string)parse_url($base, PHP_URL_HOST));
        $p = parse_url($base, PHP_URL_PORT);
        return $p ? "$h:$p" : $h;
    }

    /** Only reads: a GET not marked as changing something. */
    public static function reads(array $e): bool { return $e['method'] === 'GET' && empty($e['changes']); }

    /**
     * A spec checked and normalised; throws InvalidArgumentException saying what to fix (the model reads it).
     * $allowHttp: plain http and local addresses (the browser allows them, TALIXIO_BROWSER_ALLOW_PRIVATE).
     */
    public static function validate(array $s, bool $allowHttp = false): array
    {
        $bad = fn(string $m) => throw new \InvalidArgumentException($m);
        $name = strtolower(trim((string)($s['name'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{1,23}$/', $name)) $bad('name: 2 to 24 lowercase letters, digits or _, starting with a letter (e.g. "shop")');
        $base = rtrim(trim((string)($s['base'] ?? '')), '/');
        $p = parse_url($base);
        if (!$p || !isset($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), $allowHttp ? ['https', 'http'] : ['https'], true) || isset($p['user']) || isset($p['query']) || isset($p['fragment']))
            $bad('base: the https:// address the calls start with, e.g. "https://www.example.com/api" (no query, no login in it)');
        $auth = is_array($s['auth'] ?? null) ? $s['auth'] : ['type' => (string)($s['auth'] ?? 'none')];
        $type = strtolower((string)($auth['type'] ?? 'none'));
        if (!in_array($type, self::AUTH, true)) $bad('auth.type: one of ' . implode(', ', self::AUTH));
        $a = ['type' => $type, 'help' => mb_substr(trim((string)($auth['help'] ?? '')), 0, 300)];
        if ($type === 'header') {
            $a['name'] = trim((string)($auth['name'] ?? 'Authorization'));
            if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $a['name']) || in_array(strtolower($a['name']), ['host', 'cookie', 'content-length', 'content-type'], true)) $bad('auth.name: the header the key goes in, e.g. "Authorization" or "X-Api-Key"');
            $a['prefix'] = trim((string)($auth['prefix'] ?? (strtolower($a['name']) === 'authorization' ? 'Bearer' : '')));
            if (!preg_match('/^[A-Za-z-]{0,16}$/', $a['prefix'])) $bad('auth.prefix: a word before the key, e.g. "Bearer", or empty');
        }
        if ($type === 'query') {
            $a['name'] = trim((string)($auth['name'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $a['name'])) $bad('auth.name: the query parameter the key goes in, e.g. "api_key"');
        }
        $eps = [];
        foreach (array_slice((array)($s['endpoints'] ?? []), 0, 41) as $i => $e) {
            $at = "endpoints[$i]";
            if (!is_array($e)) $bad("$at: an object");
            $en = strtolower(trim((string)($e['name'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $en)) $bad("$at.name: lowercase letters, digits or _, e.g. \"search_products\"");
            if (isset($eps[$en])) $bad("$at.name: \"$en\" is used twice");
            $method = strtoupper(trim((string)($e['method'] ?? 'GET')));
            if (!in_array($method, self::METHODS, true)) $bad("$at.method: one of " . implode(', ', self::METHODS));
            $path = trim((string)($e['path'] ?? ''));
            if (!preg_match('#^/[A-Za-z0-9_.~!$&\'()*+,;=:@%{}/-]*$#', $path) || str_contains($path, '//') || preg_match('#(^|/)\.\.?(/|$)#', $path) || str_contains($path, '?') || str_contains($path, '#'))
                $bad("$at.path: starts with /, after the base, with {name} for values; no query (?) — put those in params with \"in\": \"query\"");
            $desc = trim((string)($e['description'] ?? ''));
            if ($desc === '') $bad("$at.description: say what it does and returns, for the assistant that will use it");
            $params = [];
            foreach ((array)($e['params'] ?? []) as $pn => $pv) {
                $pn = (string)$pn;
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,39}$/', $pn)) $bad("$at.params: bad name \"$pn\"");
                $pv = is_array($pv) ? $pv : ['type' => (string)$pv];
                $pt = strtolower((string)($pv['type'] ?? 'string'));
                if (!in_array($pt, self::TYPES, true)) $bad("$at.params.$pn.type: one of " . implode(', ', self::TYPES));
                $in = strtolower((string)($pv['in'] ?? (str_contains($path, '{' . $pn . '}') ? 'path' : ($method === 'GET' || $method === 'DELETE' ? 'query' : 'body'))));
                if (!in_array($in, ['path', 'query', 'body'], true)) $bad("$at.params.$pn.in: path, query or body");
                if ($in === 'body' && $method === 'GET') $bad("$at.params.$pn: a GET has no body: \"in\": \"query\"");
                $params[$pn] = ['type' => $pt, 'in' => $in, 'required' => $in === 'path' || !empty($pv['required']), 'description' => mb_substr(trim((string)($pv['description'] ?? '')), 0, 300)]
                    + (isset($pv['enum']) && is_array($pv['enum']) ? ['enum' => array_slice(array_values($pv['enum']), 0, 50)] : []);
            }
            preg_match_all('/\{([^}]*)\}/', $path, $mm);
            foreach ($mm[1] as $ph) if (($params[$ph]['in'] ?? '') !== 'path') $bad("$at.path: {{$ph}} needs a param \"$ph\" with \"in\": \"path\"");
            foreach ($params as $pn => $pv) if ($pv['in'] === 'path' && !str_contains($path, '{' . $pn . '}')) $bad("$at.params.$pn: \"in\": \"path\" but the path has no {{$pn}}");
            $fixed = [];
            foreach (['query', 'body', 'headers'] as $k) {
                $f = $e['fixed'][$k] ?? null;
                if ($f === null) continue;
                if (!is_array($f)) $bad("$at.fixed.$k: an object");
                if ($k === 'headers') foreach ($f as $hn => $hv) {
                    if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', (string)$hn) || in_array(strtolower((string)$hn), self::RESERVED_HEADERS, true) || !is_scalar($hv) || preg_match('/[\r\n]/', (string)$hv) || strlen((string)$hv) > 200)
                        $bad("$at.fixed.headers: \"$hn\" can't be set here (sign-in goes in auth; the transport sets its own)");
                }
                if ($k === 'body' && $method === 'GET') $bad("$at.fixed.body: a GET has no body");
                $fixed[$k] = $f;
            }
            if (strlen((string)json_encode($fixed)) > 4000) $bad("$at.fixed: too long");
            $eps[$en] = ['name' => $en, 'method' => $method, 'path' => $path, 'description' => mb_substr($desc, 0, 1000), 'params' => $params, 'fixed' => $fixed, 'changes' => !empty($e['changes'])];
        }
        if (!$eps) $bad('endpoints: at least one');
        if (count($eps) > 40) $bad('endpoints: at most 40');
        return ['name' => $name, 'title' => mb_substr(trim((string)($s['title'] ?? '')) ?: $name, 0, 80), 'base' => $base, 'auth' => $a, 'endpoints' => array_values($eps)];
    }

    /** What the user approves: where it calls, what reads and what changes, how it signs in. */
    public static function summary(array $s): string
    {
        $host = self::host($s['base']);
        $line = fn($e) => "- {$e['name']}: {$e['method']} {$e['path']} — " . mb_substr($e['description'], 0, 120);
        $reads = array_filter($s['endpoints'], [self::class, 'reads']);
        $changes = array_filter($s['endpoints'], fn($e) => !self::reads($e));
        return "Add a connector learned from $host: \"{$s['title']}\" ({$s['name']})\n"
            . "It only ever calls {$s['base']}.\n"
            . ($reads ? "Reads (run without asking):\n" . implode("\n", array_map($line, $reads)) . "\n" : '')
            . ($changes ? "Changes (ask you each time):\n" . implode("\n", array_map($line, $changes)) . "\n" : '')
            . 'Signs in with: ' . match ($s['auth']['type']) {
                'cookies' => "your login to $host in the assistant's browser (log in there again when it expires)",
                'header', 'query' => 'a key you enter in Settings → Connected accounts → Connectors (never in the chat)',
                default => 'nothing (public calls)',
            };
    }

    /** Save (or replace) a learned connector. A key already entered stays when the sign-in stays the same. */
    public function save(array $spec): array
    {
        $s = self::validate($spec, $this->app->cfg->browserAllowPrivate);
        if (isset($this->app->mcp()->servers()[$s['name']])) throw new \InvalidArgumentException("name: \"{$s['name']}\" is already a connector in mcp.json: choose another name");
        return JsonFile::update($this->file(), function (array $d) use ($s) {
            $old = $d['apis'][$s['name']] ?? null;
            $keep = $old && ($old['auth']['type'] ?? '') === $s['auth']['type'] && ($old['auth']['name'] ?? '') === ($s['auth']['name'] ?? '') && self::host($old['base']) === self::host($s['base']);
            $d['apis'][$s['name']] = $s + ['key' => $keep ? (string)($old['key'] ?? '') : '', 'at' => time()];
            return [$d, $s + ['replaced' => $old !== null]];
        }, 0600, JSON_PRETTY_PRINT);
    }

    public function remove(string $name): bool
    {
        return JsonFile::update($this->file(), function (array $d) use ($name) {
            if (!isset($d['apis'][$name])) return [null, false];
            unset($d['apis'][$name]);
            return [$d, true];
        }, 0600, JSON_PRETTY_PRINT);
    }

    /** The key for a "header" or "query" sign-in, from Settings; kept sealed. Empty removes it. */
    public function setKey(string $name, string $key): void
    {
        $key = trim($key);
        if (preg_match('/[\r\n]/', $key) || strlen($key) > 4000) throw new \InvalidArgumentException('That key is not valid.');
        JsonFile::update($this->file(), function (array $d) use ($name, $key) {
            if (!isset($d['apis'][$name])) throw new \InvalidArgumentException("no learned connector $name");
            if (!in_array($d['apis'][$name]['auth']['type'] ?? '', ['header', 'query'], true)) throw new \InvalidArgumentException('This connector signs in without a key.');
            $d['apis'][$name]['key'] = $key === '' ? '' : Secrets::seal($key);
            return [$d, null];
        }, 0600, JSON_PRETTY_PRINT);
    }

    /** One tool per endpoint, like a connector's: reads run without asking, changes ask each time. */
    public function register(Tools $tools): void
    {
        foreach ($this->all() as $name => $s) $this->registerOne($tools, $name, $s);
    }

    public function registerOne(Tools $tools, string $name, array $s): array
    {
        $host = self::host($s['base']);
        $names = [];
        foreach ($s['endpoints'] as $e) {
            $tool = Mcp::toolName($name, $e['name']);
            $props = [];
            foreach ($e['params'] as $pn => $pv) {
                $props[$pn] = ['type' => $pv['type']] + ($pv['description'] !== '' ? ['description' => $pv['description']] : []) + (isset($pv['enum']) ? ['enum' => $pv['enum']] : [])
                    + ($pv['type'] === 'array' ? ['items' => new \stdClass()] : []);
            }
            $req = array_keys(array_filter($e['params'], fn($p) => $p['required']));
            $reads = self::reads($e);
            $tools->register($tool, "[$name] {$e['description']} (learned from $host: {$e['method']} {$e['path']})", $props, $req,
                fn(array $a) => $this->call($name, $e['name'], $a),
                $reads ? null : fn(array $a) => "Use \"{$e['name']}\" on $host ({$e['method']} {$e['path']}): " . mb_substr(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1500));
            if ($reads) $tools->readOnly[] = $tool;
            $names[] = $tool;
        }
        return $names;
    }

    /** The request an endpoint makes with these arguments: [method, url, headers, body|null]. Throws when it can't. */
    public function build(array $s, array $e, array $a): array
    {
        foreach ($e['params'] as $pn => $pv) if ($pv['required'] && (!array_key_exists($pn, $a) || $a[$pn] === '' || $a[$pn] === null)) throw new \InvalidArgumentException("missing $pn");
        $path = preg_replace_callback('/\{([^}]+)\}/', fn($m) => rawurlencode(is_scalar($a[$m[1]] ?? '') ? (string)$a[$m[1]] : ''), $e['path']);
        $query = (array)($e['fixed']['query'] ?? []);
        $body = (array)($e['fixed']['body'] ?? []);
        foreach ($e['params'] as $pn => $pv) {
            if (!array_key_exists($pn, $a) || $pv['in'] === 'path') continue;
            $v = $a[$pn];
            if ($pv['in'] === 'query') $query[$pn] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
            else $body[$pn] = $v;
        }
        $headers = ['Accept: application/json, text/plain;q=0.8, */*;q=0.5'];
        foreach ((array)($e['fixed']['headers'] ?? []) as $hn => $hv) $headers[] = "$hn: $hv";
        $key = Secrets::open((string)($s['key'] ?? ''));
        $type = $s['auth']['type'];
        if (in_array($type, ['header', 'query'], true) && $key === '') throw new NeedsKey("This connector needs its key: the user enters it in Settings → Connected accounts → Connectors → {$s['title']}" . ($s['auth']['help'] !== '' ? " ({$s['auth']['help']})" : '') . '. Never ask for it in the chat.');
        if ($type === 'query') $query[$s['auth']['name']] = $key;
        $url = $s['base'] . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        if (self::host($url) !== self::host($s['base']) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== strtolower((string)parse_url($s['base'], PHP_URL_SCHEME))) throw new \RuntimeException('refused: the call would leave ' . self::host($s['base']));
        if ($type === 'header') $headers[] = $s['auth']['name'] . ': ' . ($s['auth']['prefix'] !== '' ? $s['auth']['prefix'] . ' ' : '') . $key;
        if ($type === 'cookies') {
            $c = self::$cookies ? (string)(self::$cookies)($url) : Browser::get($this->app)->cookieHeader($url);
            if ($c !== '') $headers[] = "Cookie: $c";
        }
        $hasBody = $e['method'] !== 'GET' && ($body || in_array($e['method'], ['POST', 'PUT', 'PATCH'], true));
        if ($hasBody) $headers[] = 'Content-Type: application/json';
        return [$e['method'], $url, $headers, $hasBody ? json_encode($body ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null];
    }

    /** Call an endpoint; the site's answer as text, "error: …" with what to do when it refuses. */
    public function call(string $name, string $endpoint, array $a): string
    {
        $s = $this->get($name) ?? throw new \RuntimeException("the connector $name was removed");
        $e = array_values(array_filter($s['endpoints'], fn($x) => $x['name'] === $endpoint))[0] ?? throw new \RuntimeException("$endpoint is not in the connector $name any more");
        $host = self::host($s['base']);
        try { [$method, $url, $headers, $body] = $this->build($s, $e, $a); }
        catch (NeedsKey $x) { return 'error: ' . $x->getMessage(); }
        catch (\InvalidArgumentException $x) { return 'error: ' . $x->getMessage(); }
        $opts = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 5_000_000];
        if (!$this->app->cfg->browserAllowPrivate) { // a public address only, pinned (no DNS change between the check and the call)
            $p = parse_url($url); $port = (int)($p['port'] ?? 443);
            $ip = Http::publicIp((string)$p['host']);
            $opts[CURLOPT_RESOLVE] = [$p['host'] . ":$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip)];
        }
        [$code, $out, $h] = Privacy::as('connector', fn() => Http::request($method, $url, $headers, $body, 60, $opts));
        $login = $s['auth']['type'] === 'cookies';
        if ($code === 401 || $code === 403 || ($login && $code >= 300 && $code < 400)) {
            return "error: $host refused the call (HTTP $code): " . match ($s['auth']['type']) {
                'cookies' => "the login to $host has expired or is missing. Tell the user, and ask them to log in again: open https://$host with browser_open, they give their login (typing it asks their approval), then try again.",
                'header', 'query' => "the key is refused or expired. Tell the user to enter a new one in Settings → Connected accounts → Connectors → {$s['title']}; never ask for it in the chat.",
                default => 'this call needs a sign-in the connector does not have. Learn the API again while logged in, with auth "cookies".',
            };
        }
        $type = (string)($h['content-type'] ?? '');
        $text = $out;
        if (str_contains($type, 'json') && ($j = json_decode($out, true)) !== null) $text = (string)json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        elseif (preg_match('#text/html#i', $type)) $text = Http::htmlToText($out);
        $text = mb_substr($text, 0, 30000) . (mb_strlen($text) > 30000 ? "\n[… cut]" : '');
        return ($code >= 400 ? 'error: ' : '') . "HTTP $code\n" . ($text !== '' ? $text : '(empty)');
    }

    // ---- cleaning what the browser saw, before the model reads it ----

    /** A parameter or field name that holds a secret (password, token, key, session, signature…). */
    public static function secretName(string $n): bool
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1_$2', $n))) ?: [];
        foreach ($parts as $p) if (in_array($p, ['pass', 'password', 'passwd', 'pwd', 'secret', 'token', 'auth', 'authorization', 'session', 'sess', 'sid', 'csrf', 'xsrf',
            'cookie', 'key', 'apikey', 'signature', 'sig', 'otp', 'bearer', 'jwt', 'credential', 'credentials', 'nonce', 'hash', 'pin'], true)) return true;
        return (bool)preg_match('/password|token|secret|apikey|session|csrf|xsrf/', strtolower($n));
    }

    /** A value that looks like a secret whatever its name: a JWT, or a long opaque string. */
    public static function secretValue(string $v): bool
    {
        return (bool)preg_match('/^eyJ[\w-]+\.[\w-]+\.[\w-]*$/', $v) || (strlen($v) >= 32 && preg_match('/^[A-Za-z0-9+\/_=.-]+$/', $v) && preg_match('/\d/', $v) && preg_match('/[A-Za-z]/', $v) && !preg_match('/^\d{4}-\d\d-\d\dT/', $v));
    }

    /** JSON data cleaned: secret fields and values replaced, long strings cut, lists shortened to their first items. */
    public static function scrubData(mixed $d, int $depth = 0): mixed
    {
        if (is_array($d)) {
            if ($depth > 6) return '…';
            $list = array_is_list($d);
            $out = [];
            foreach (array_slice($d, 0, $list ? 3 : 40, true) as $k => $v) $out[$k] = !$list && self::secretName((string)$k) && !is_array($v) ? '<secret>' : self::scrubData($v, $depth + 1);
            if ($list && count($d) > 3) $out[] = '… (' . count($d) . ' items)';
            if (!$list && count($d) > 40) $out['…'] = (count($d) - 40) . ' more fields';
            return $out;
        }
        if (is_string($d)) return self::secretValue($d) ? '<secret>' : (mb_strlen($d) > 120 ? mb_substr($d, 0, 120) . '…' : $d);
        return $d;
    }

    /** A query string or form body cleaned the same way. */
    public static function scrubQuery(string $q): string
    {
        if ($q === '') return '';
        $parts = [];
        foreach (explode('&', $q) as $kv) {
            [$k, $v] = explode('=', $kv, 2) + [1 => ''];
            $dv = urldecode($v);
            $parts[] = $k . '=' . (self::secretName(urldecode($k)) || self::secretValue($dv) ? '<secret>' : rawurlencode(mb_substr($dv, 0, 120)));
        }
        return implode('&', $parts);
    }

    /**
     * One call the browser saw, as the model may read it: method, address and body cleaned, status and type, the
     * answer's shape, and how it signed in (a hint for auth: cookies or a header), never the cookies or tokens.
     * $c: method, url, post, status, mime, body, cookies (bool), auth_header (bool).
     */
    public static function scrub(array $c): array
    {
        $u = (string)$c['url'];
        $q = (string)parse_url($u, PHP_URL_QUERY);
        $base = preg_replace('/[?#].*$/s', '', $u);
        $post = (string)($c['post'] ?? '');
        $pj = $post !== '' ? json_decode($post, true) : null;
        $out = ['method' => (string)$c['method'], 'url' => $base . ($q !== '' ? '?' . self::scrubQuery($q) : ''), 'status' => (int)($c['status'] ?? 0), 'type' => (string)($c['mime'] ?? '')];
        if ($post !== '') $out['request_body'] = is_array($pj) ? self::scrubData($pj) : mb_substr(self::scrubQuery($post), 0, 1000);
        $signs = array_merge(!empty($c['cookies']) ? ['cookies'] : [], !empty($c['auth_header']) ? ['an Authorization header'] : []);
        $out['signed_in_with'] = $signs ? implode(' and ', $signs) : 'nothing';
        $body = (string)($c['body'] ?? '');
        $bj = $body !== '' ? json_decode($body, true) : null;
        $data = $bj !== null ? self::scrubData($bj) : null;
        $enc = $data !== null ? (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : mb_substr(trim($body), 0, 300);
        $out['response'] = $data !== null && strlen($enc) <= 2500 ? $data : (mb_strlen($enc) > 2500 ? mb_substr($enc, 0, 2500) . '…' : $enc); // as data when short, so it reads as JSON
        return $out;
    }
}

/** An endpoint needs a key the user has not entered yet. */
final class NeedsKey extends \RuntimeException {}
