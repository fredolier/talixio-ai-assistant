<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Small apps shown in the Apps menu, built by the assistant (or added from the examples) out of a fixed kit
 * (web/public/appkit.js: charts, tables, figures, forms). Each app runs in a sandboxed frame of its own origin, with no
 * network and no access to the session: its only way out is talixio.data(), which reaches the data sources it declared
 * (a web address, a library file, the space's tasks, a saved script), run by the server under the usual privacy rules,
 * and talixio.load/save, a small store of its own. An app the assistant wrote or changed waits for the person's approval
 * (they see what it may reach and its code) before it gets any data.
 */
final class Apps
{
    public const TYPES = ['web', 'file', 'tasks', 'script', 'folder', 'search', 'calendar', 'contacts', 'mcp'];
    private const MAX_CODE = 200_000;
    private const MAX_STATE = 200_000;
    private const WEB_PER_HOUR = 60;

    public function __construct(private App $app)
    {
        $app->store->db->exec("CREATE TABLE IF NOT EXISTS apps(id INTEGER PRIMARY KEY, space INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '', icon TEXT NOT NULL DEFAULT '', html TEXT NOT NULL DEFAULT '', script TEXT NOT NULL DEFAULT '',
                sources TEXT NOT NULL DEFAULT '[]', status TEXT NOT NULL DEFAULT 'pending', created INTEGER NOT NULL, updated INTEGER NOT NULL, created_by TEXT NOT NULL DEFAULT 'assistant');
            CREATE TABLE IF NOT EXISTS app_state(app INTEGER NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY(app, key));
            CREATE TABLE IF NOT EXISTS app_cache(app INTEGER NOT NULL, k TEXT NOT NULL, ts INTEGER NOT NULL, body TEXT NOT NULL, PRIMARY KEY(app, k));");
        $app->store->addColumn('apps', 'origin', "TEXT NOT NULL DEFAULT '{}'"); // where an installed app came from, and how to install its connectors
    }

    /** Apps the person may see from this space: a Secret space's apps only inside it. */
    public function list(): array
    {
        $rows = $this->app->store->q('SELECT id, space, name, description, icon, status, created, updated, created_by FROM apps ORDER BY name COLLATE NOCASE')->fetchAll();
        $p = $this->app->privacy();
        return array_values(array_map(fn($r) => self::cast($r), array_filter($rows, fn($r) => !$p->hidden($p->spaceLevel((int)$r['space']), (int)$r['space']))));
    }

    public function get(int $id): ?array
    {
        $r = $this->app->store->q('SELECT * FROM apps WHERE id = ?', [$id])->fetch();
        if (!$r) return null;
        $p = $this->app->privacy();
        if ($p->hidden($p->spaceLevel((int)$r['space']), (int)$r['space'])) return null;
        $r = self::cast($r);
        $r['sources'] = json_decode((string)$r['sources'], true) ?: [];
        $r['origin'] = json_decode((string)($r['origin'] ?? ''), true) ?: [];
        return $r;
    }

    private static function cast(array $r): array
    {
        foreach (['id', 'space', 'created', 'updated'] as $k) $r[$k] = (int)$r[$k];
        return $r;
    }

    /**
     * Creates or replaces an app. One saved by the assistant waits for approval; the person's own (an example) does not.
     * @return array the app
     */
    public function save(array $a, string $by = 'assistant', ?int $id = null): array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)($a['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 60) throw new \InvalidArgumentException('name: 1 to 60 characters');
        $html = (string)($a['html'] ?? ''); $script = (string)($a['script'] ?? '');
        if (strlen($html) + strlen($script) > self::MAX_CODE) throw new \InvalidArgumentException('html and script: 200 KB at most');
        if ($e = self::checkCode($html, $script)) throw new \InvalidArgumentException($e);
        $sources = self::checkSources((array)($a['sources'] ?? []));
        $old = $id ? $this->get($id) : null;
        if ($id && !$old) throw new \InvalidArgumentException("no app #$id");
        $now = time();
        $row = [$name, mb_substr(trim((string)($a['description'] ?? '')), 0, 300), mb_substr(trim((string)($a['icon'] ?? '')), 0, 4), $html, $script,
            json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $by === 'you' ? 'approved' : 'pending', $now];
        if ($old) {
            $this->app->store->q('UPDATE apps SET name=?, description=?, icon=?, html=?, script=?, sources=?, status=?, updated=? WHERE id=?', [...$row, $old['id']]);
            $this->app->store->q('DELETE FROM app_cache WHERE app = ?', [$old['id']]);
        } else {
            $this->app->store->q('INSERT INTO apps(name, description, icon, html, script, sources, status, updated, created, created_by, space) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [...$row, $now, $by, $this->app->space]);
            $id = (int)$this->app->store->db->lastInsertId();
        }
        return $this->get($id);
    }

    public const PACKAGE = 'talixio-app';

    /**
     * An app as a package for another assistant (a linked one, or anyone, as a file): its code, what it reads, and how to
     * install the connectors it uses, without their secrets (no token, header value, key or address query). Never its
     * data, its saved state or its cache. An app of a Secret space stays here.
     */
    public function export(int $id): array
    {
        $a = $this->get($id) ?? throw new \InvalidArgumentException("no app #$id");
        $p = $this->app->privacy();
        if ($p->spaceLevel($a['space']) === 'secret') throw new \InvalidArgumentException('An app of a Secret space stays in this assistant.');
        $servers = $this->app->mcp()->servers(); $conn = [];
        $sources = array_map(function (array $s) use ($servers, &$conn, $p) {
            if ($s['type'] === 'file' && ($f = $this->app->files()->get($s['file'])) && !$p->hidden($p->folderLevel((string)$f['folder']), null, (string)$f['folder']))
                $s['path'] = rtrim((string)$f['folder'], '/') . '/' . $f['name']; // the same file, found by its place, in the other library
            if ($s['type'] === 'mcp') {
                if (isset($servers[$s['server']])) $conn[$s['server']] = self::recipe($servers[$s['server']]);
                elseif (isset($a['origin']['connectors'][$s['server']])) $conn[$s['server']] = $a['origin']['connectors'][$s['server']]; // passed on as received
            }
            return $s;
        }, $a['sources']);
        return ['format' => self::PACKAGE, 'version' => 1, 'name' => $a['name'], 'description' => $a['description'], 'icon' => $a['icon'],
            'html' => $a['html'], 'script' => $a['script'], 'sources' => $sources, 'connectors' => $conn];
    }

    /** How to install a connector elsewhere: its GitHub repository, or its address (no query, no user) and the names of the headers to fill. */
    public static function recipe(array $s): array
    {
        $r = array_filter(['description' => mb_substr(trim((string)($s['description'] ?? '')), 0, 300)]);
        if ($gh = (string)($s['source']['github'] ?? '')) return $r + ['github' => $gh];
        $u = parse_url((string)($s['url'] ?? ''));
        if (($u['scheme'] ?? '') === 'https' && ($u['host'] ?? '') !== '')
            return $r + ['url' => 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . ($u['path'] ?? '')]
                + (!empty($s['headers']) ? ['headers' => array_values(array_map('strval', array_keys((array)$s['headers'])))] : []);
        return $r + ['manual' => true]; // a local one from a .zip or a command: set up by hand there
    }

    /** A received connector recipe, kept to what can install it: a GitHub repository, or an https address and header names. */
    public static function cleanRecipe(array $r): array
    {
        $c = array_filter(['description' => mb_substr((string)($r['description'] ?? ''), 0, 300)]);
        if (preg_match('#^https://(www\.)?github\.com/[\w.\-/]+$#i', (string)($r['github'] ?? ''))) $c['github'] = (string)$r['github'];
        elseif (preg_match('#^https://[^\s/?\#@]+(/[^\s?\#]*)?$#i', (string)($r['url'] ?? ''))) $c += ['url' => (string)$r['url'], 'headers' => array_values(array_filter(array_slice(array_map('strval', (array)($r['headers'] ?? [])), 0, 10), fn($h) => (bool)preg_match('/^[\w-]{1,60}$/', $h)))];
        else $c['manual'] = true;
        return $c;
    }

    /**
     * Installs a package (a file, or sent by a linked assistant) in this space. It always waits for approval, like an app
     * the assistant wrote. A library file it read is looked for at the same place here; not found, that source is left out.
     * $id: an app installed earlier from the same place, changed in place (it waits for approval again); $extra joins its origin.
     * @return array the app
     */
    public function import(array $pkg, string $from = '', ?int $id = null, array $extra = []): array
    {
        if (($pkg['format'] ?? '') !== self::PACKAGE) throw new \InvalidArgumentException('This is not a Talixio app (.talixio-app.json).');
        $dropped = []; $sources = [];
        foreach (array_slice((array)($pkg['sources'] ?? []), 0, 10) as $s) {
            $s = (array)$s;
            if (($s['type'] ?? '') === 'file') {
                $path = (string)($s['path'] ?? '');
                $f = $path !== '' ? $this->app->store->q('SELECT id FROM files WHERE folder = ? AND name = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
                    [Library::normalize(dirname($path)), basename($path)])->fetch() : null;
                if (!$f) { $dropped[] = ['name' => (string)($s['name'] ?? ''), 'path' => $path]; continue; }
                $s['file'] = (int)$f['id'];
            }
            unset($s['path']);
            $sources[] = $s;
        }
        $conn = [];
        foreach ((array)($pkg['connectors'] ?? []) as $name => $r) {
            $name = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$name)); $r = (array)$r;
            if ($name === '') continue;
            $conn[$name] = self::cleanRecipe($r);
        }
        $a = $this->save(['name' => $pkg['name'] ?? '', 'description' => $pkg['description'] ?? '', 'icon' => $pkg['icon'] ?? '',
            'html' => $pkg['html'] ?? '', 'script' => $pkg['script'] ?? '', 'sources' => $sources], 'import', $id);
        $origin = ['from' => mb_substr($from, 0, 80), 'connectors' => $conn, 'dropped' => $dropped, 'at' => time()] + $extra;
        $this->app->store->q('UPDATE apps SET origin = ? WHERE id = ?', [json_encode($origin, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $a['id']]);
        return $this->get($a['id']);
    }

    /** What the app needs here: each connector it reads (there or not, how to install it) and whether its tools run without asking. */
    public function needs(array $a): array
    {
        $servers = $this->app->mcp()->servers(); $out = [];
        foreach ($a['sources'] as $s) {
            if ($s['type'] !== 'mcp') continue;
            $srv = $servers[$s['server']] ?? null;
            $out[$s['server']] ??= ['server' => $s['server'], 'installed' => $srv !== null, 'recipe' => $a['origin']['connectors'][$s['server']] ?? null, 'tools' => []];
            $out[$s['server']]['tools'][$s['tool']] = ['tool' => $s['tool'], 'allowed' => $srv !== null && in_array($s['tool'], array_map('strval', (array)($srv['read_only'] ?? [])), true)];
        }
        return array_values(array_map(fn($n) => ['tools' => array_values($n['tools'])] + $n, $out));
    }

    public function approve(int $id): void
    {
        if (!$this->get($id)) throw new \InvalidArgumentException("no app #$id");
        $this->app->store->q("UPDATE apps SET status = 'approved' WHERE id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        if (!$this->get($id)) return false;
        foreach (['apps WHERE id', 'app_state WHERE app', 'app_cache WHERE app'] as $t) $this->app->store->q("DELETE FROM $t = ?", [$id]);
        return true;
    }

    /** What the kit refuses (the frame's policy blocks it anyway; this tells the assistant early). Null: fine. */
    public static function checkCode(string $html, string $script): ?string
    {
        if (preg_match('/<\s*(script|iframe|frame|object|embed|base|link|meta)\b/i', $html, $m)) return "html: no <{$m[1]}> (put code in script; data only through talixio.data)";
        if (preg_match('/\son[a-z]+\s*=/i', $html)) return 'html: no inline event handlers (onclick=...); use addEventListener in script';
        if (preg_match('/javascript:/i', $html)) return 'html: no javascript: links';
        if (preg_match('/\b(fetch|XMLHttpRequest|WebSocket|EventSource|importScripts|eval|Function|sendBeacon)\s*\(|\bimport\s*\(|\bnew\s+(Worker|Function)\b|\b(window\.)?(parent|top|opener)\s*\.|document\.cookie|\b(localStorage|sessionStorage|indexedDB)\b|<\/script/i', $script, $m))
            return "script: '{$m[0]}' is not allowed; read data with talixio.data(), keep state with talixio.load/save";
        return null;
    }

    /** @return list<array> the sources, checked */
    public static function checkSources(array $list): array
    {
        $out = [];
        foreach (array_slice($list, 0, 10) as $s) {
            $s = (array)$s;
            $name = (string)($s['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name)) throw new \InvalidArgumentException("source name '$name': lowercase letters, digits, _");
            $type = (string)($s['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException("source $name: type is one of " . implode(', ', self::TYPES));
            $c = ['name' => $name, 'type' => $type];
            if ($type === 'web') {
                $url = (string)($s['url'] ?? '');
                $host = (string)parse_url($url, PHP_URL_HOST);
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || str_contains($host, '{')) throw new \InvalidArgumentException("source $name: an https address, its host fixed ({params} only in the path or query)");
                $c['url'] = $url;
            } elseif ($type === 'file') {
                $c['file'] = (int)($s['file'] ?? 0);
                if ($c['file'] <= 0) throw new \InvalidArgumentException("source $name: file is a library file id");
            } elseif ($type === 'script') {
                $c['script'] = preg_replace('/\.py$/', '', (string)($s['script'] ?? ''));
                if ($c['script'] === '') throw new \InvalidArgumentException("source $name: script is the name of a saved script");
                $c['args'] = array_map('strval', array_slice((array)($s['args'] ?? []), 0, 20));
            } elseif ($type === 'folder' || $type === 'search') {
                $c['folder'] = Library::normalize((string)($s['folder'] ?? '/'));
                if ($type === 'folder' && $c['folder'] === '/') throw new \InvalidArgumentException("source $name: folder is a library folder, like /Reports/OVH");
            } elseif ($type === 'calendar') {
                $c['days'] = max(1, min(90, (int)($s['days'] ?? 14)));
            } elseif ($type === 'mcp') {
                $c['server'] = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($s['server'] ?? '')));
                $c['tool'] = (string)($s['tool'] ?? '');
                if ($c['server'] === '' || !preg_match('/^[\w.\/-]{1,80}$/', $c['tool'])) throw new \InvalidArgumentException("source $name: server (a connector) and tool (one of its tools)");
                $args = $s['args'] ?? [];
                if (is_string($args)) $args = json_decode($args, true) ?: [];
                $c['args'] = array_slice((array)$args, 0, 20, true);
            }
            if (in_array($type, ['web', 'script', 'mcp'], true)) $c['ttl'] = max(5, min(1440, (int)($s['ttl'] ?? 30)));
            $c['format'] = in_array($s['format'] ?? '', ['json', 'csv', 'text'], true) ? $s['format'] : (in_array($type, ['tasks', 'folder', 'search', 'calendar', 'contacts'], true) ? 'json' : 'text');
            $out[] = $c;
        }
        return $out;
    }

    /** A placeholder's value from the app: words, numbers and common punctuation, URL-encoded (or one shell argument for a script). */
    private static function fill(string $tpl, array $params, bool $url): string
    {
        return preg_replace_callback('/\{([a-z][a-z0-9_]{0,30})\}/', function ($m) use ($params, $url) {
            $v = (string)($params[$m[1]] ?? '');
            if (!preg_match('/^[\p{L}\p{N}\p{Zs}._:,\-+\/@\'()&#%?=!€$]{0,200}$/u', $v)) throw new \InvalidArgumentException("parameter {$m[1]}: letters, digits, spaces and common punctuation, 200 characters at most");
            return $url ? rawurlencode($v) : $v;
        }, $tpl);
    }

    /** The data an approved app asked for, from one of its sources. */
    public function data(int $id, string $name, array $params = []): mixed
    {
        $app = $this->get($id) ?? throw new \InvalidArgumentException("no app #$id");
        if ($app['status'] !== 'approved') throw new \RuntimeException('approve this app first (Apps → the app → Approve)');
        $src = current(array_filter($app['sources'], fn($s) => $s['name'] === $name)) ?: throw new \InvalidArgumentException("this app has no data source '$name'");
        $was = $this->app->space;
        $this->app->space = $app['space']; // the app's data is read as from its own space
        try {
            return Privacy::forPerson(fn() => $this->read($app, $src, $params));
        } finally { $this->app->space = $was; }
    }

    private function read(array $app, array $src, array $params): mixed
    {
        $p = $this->app->privacy();
        $lvl = $p->spaceLevel($app['space']);
        if ($src['type'] === 'tasks') {
            $bd = $this->app->board();
            return array_map($bd->brief(...), $bd->list(['space' => $app['space'], 'done_days' => 30]));
        }
        if ($src['type'] === 'file') {
            $f = $this->app->files()->get($src['file']) ?? throw new \RuntimeException("file #{$src['file']} is not in the library");
            if ($p->hidden($p->folderLevel((string)$f['folder']), null, (string)$f['folder'])) throw new \RuntimeException("file #{$src['file']} belongs to a Secret space");
            return self::parse($this->app->files()->text((int)$f['id']), $src['format']);
        }
        if ($src['type'] === 'folder' || $src['type'] === 'search') {
            if ($p->hidden($p->folderLevel($src['folder']), null, $src['folder'])) throw new \RuntimeException("the folder {$src['folder']} belongs to a Secret space");
            $lib = $this->app->library();
            if ($src['type'] === 'search') {
                $q = trim((string)($params['q'] ?? ''));
                return $q === '' ? [] : $lib->search(mb_substr($q, 0, 200), $src['folder'], 20, false);
            }
            if (isset($params['file'])) { // one file of the folder (or below it), read like a file source
                $f = $this->app->files()->get((int)$params['file']);
                $in = $f && ($f['folder'] === $src['folder'] || str_starts_with((string)$f['folder'], rtrim($src['folder'], '/') . '/'));
                if (!$in || $p->hidden($p->folderLevel((string)$f['folder']), null, (string)$f['folder'])) throw new \RuntimeException("file #{$params['file']} is not in {$src['folder']}");
                $fmt = in_array($params['format'] ?? '', ['json', 'csv', 'text'], true) ? $params['format'] : (preg_match('/\.(json|csv)$/i', (string)$f['name'], $fm) ? strtolower($fm[1]) : 'text');
                return self::parse($this->app->files()->text((int)$f['id']), $fmt);
            }
            [$w, $wp] = Library::within(rtrim($src['folder'], '/') ?: '/');
            $rows = $this->app->store->q("SELECT id, name, folder, size, created, changed, changed_by FROM files WHERE $w AND deleted_at IS NULL ORDER BY COALESCE(changed, created) DESC LIMIT 500", $wp)->fetchAll();
            return array_values(array_filter(array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'folder' => $r['folder'], 'size' => (int)$r['size'], 'created' => date('c', (int)$r['created']),
                'changed' => date('c', (int)($r['changed'] ?? $r['created'])), 'changed_by' => (string)$r['changed_by']], $rows),
                fn($r) => !$p->hidden($p->folderLevel($r['folder']), null, $r['folder'])));
        }
        if ($src['type'] === 'calendar') {
            return array_map(fn($e) => ['title' => (string)($e['title'] ?? ''), 'start' => date('c', (int)$e['start']), 'end' => date('c', (int)$e['end']), 'all_day' => (bool)($e['all_day'] ?? false), 'location' => (string)($e['location'] ?? '')],
                $this->app->calendar()->events(time() - 3600, time() + $src['days'] * 86400));
        }
        if ($src['type'] === 'contacts') {
            return array_map(fn($c) => array_intersect_key($c, array_flip(['id', 'name', 'email', 'phone', 'org', 'last_ts'])), $this->app->contacts()->search(mb_substr(trim((string)($params['q'] ?? '')), 0, 100), 200));
        }
        $k = $src['name'] . ':' . json_encode($params);
        $hit = $this->app->store->q('SELECT ts, body FROM app_cache WHERE app = ? AND k = ?', [$app['id'], $k])->fetch();
        if ($hit && $hit['ts'] > time() - $src['ttl'] * 60) return self::parse((string)$hit['body'], $src['format']);
        if ($src['type'] === 'web' || $src['type'] === 'mcp') {
            if ($lvl === 'secret') throw new \RuntimeException('an app of a Secret space never reaches the web or a connector');
            if ($this->readsConfidential($app)) throw new \RuntimeException('an app that reads Confidential documents never reaches the web or a connector');
            $n = (int)$this->app->store->q('SELECT COUNT(*) FROM app_cache WHERE app = ? AND ts > ?', [$app['id'], time() - 3600])->fetchColumn();
            if ($n >= self::WEB_PER_HOUR) throw new \RuntimeException('too many requests this hour; try again later');
        }
        if ($src['type'] === 'mcp') {
            $mcp = $this->app->mcp();
            $srv = $mcp->servers()[$src['server']] ?? throw new \RuntimeException("no connector {$src['server']}");
            // only a tool you marked as only reading, in Settings → Connectors: an app never changes anything through a connector
            if (!in_array($src['tool'], array_map('strval', (array)($srv['read_only'] ?? [])), true))
                throw new \RuntimeException("the tool {$src['tool']} of {$src['server']} is not marked as only reading (Settings → Connectors)");
            $args = array_map(fn($v) => is_string($v) ? self::fill($v, $params, false) : $v, $src['args']);
            $body = $mcp->call($src['server'], $src['tool'], $args);
            if (str_starts_with($body, 'error:')) throw new \RuntimeException($body);
        } elseif ($src['type'] === 'web') {
            [$code, $body] = Http::safeGet(self::fill($src['url'], $params, true), 2_000_000, 20);
            if ($code >= 400) throw new \RuntimeException("the site answered HTTP $code");
        } else {
            $s = $this->app->scripts();
            if (!$this->app->shell()->enabled() || $s->read($src['script']) === null) throw new \RuntimeException("no script named '{$src['script']}'");
            if (!$s->approved($src['script']) && $this->app->cfg->shell === 'ask') throw new \RuntimeException("the script {$src['script']} changed since you approved it: run it once in the chat");
            $args = array_map(fn($a) => self::fill($a, $params, false), $src['args']);
            foreach ($args as $a) if (str_starts_with($a, '-')) throw new \InvalidArgumentException('a script argument may not start with -');
            $body = $this->app->shell()->run($s->command($src['script'], $args), []);
        }
        if (!mb_check_encoding($body, 'UTF-8')) $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8, ISO-8859-1');
        $this->app->store->q('DELETE FROM app_cache WHERE app = ? AND ts < ?', [$app['id'], time() - 86400]); // longest ttl: a day
        $this->app->store->q('INSERT OR REPLACE INTO app_cache(app, k, ts, body) VALUES(?,?,?,?)', [$app['id'], $k, time(), $body]);
        return self::parse($body, $src['format']);
    }

    /** Whether the app's space, or a file or folder it reads, is Confidential (or more). */
    private function readsConfidential(array $app): bool
    {
        $p = $this->app->privacy();
        if ($p->spaceLevel($app['space']) !== 'internal') return true;
        foreach ($app['sources'] as $s) {
            $folder = $s['type'] === 'file' ? (string)($this->app->files()->get((int)$s['file'])['folder'] ?? '') : (in_array($s['type'], ['folder', 'search'], true) ? $s['folder'] : null);
            if ($folder !== null && $folder !== '' && $p->folderLevel($folder) !== 'internal') return true;
        }
        return false;
    }

    private static function parse(string $body, string $format): mixed
    {
        if ($format === 'json') {
            $j = json_decode($body, true);
            if ($j === null && trim($body) !== 'null') throw new \RuntimeException('the source did not return JSON: ' . mb_substr(trim($body), 0, 120));
            return $j;
        }
        return $format === 'csv' ? self::csv($body) : $body;
    }

    /** CSV text as a list of rows keyed by the header line (comma, semicolon or tab). */
    public static function csv(string $text): array
    {
        $lines = preg_split('/\r?\n/', trim($text));
        if (!$lines || $lines[0] === '') return [];
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $head = str_getcsv(array_shift($lines), $sep, '"', '');
        $rows = [];
        foreach (array_slice($lines, 0, 20000) as $l) {
            if (trim($l) === '') continue;
            $v = str_getcsv($l, $sep, '"', '');
            $rows[] = array_combine($head, array_pad(array_slice($v, 0, count($head)), count($head), ''));
        }
        return $rows;
    }

    /** talixio.load(key) / talixio.save(key, value): the app's own small store. */
    public function state(int $id, string $key, mixed $value = null, bool $write = false): mixed
    {
        if (!$this->get($id)) throw new \InvalidArgumentException("no app #$id");
        if (!preg_match('/^[\w.-]{1,60}$/', $key)) throw new \InvalidArgumentException('key: letters, digits, . _ -');
        if (!$write) {
            $v = $this->app->store->q('SELECT value FROM app_state WHERE app = ? AND key = ?', [$id, $key])->fetchColumn();
            return $v === false ? null : json_decode((string)$v, true);
        }
        if ($value === null) { $this->app->store->q('DELETE FROM app_state WHERE app = ? AND key = ?', [$id, $key]); return null; }
        $j = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (strlen($j) > self::MAX_STATE) throw new \InvalidArgumentException('value: 200 KB at most');
        if ((int)$this->app->store->q('SELECT COUNT(*) FROM app_state WHERE app = ? AND key != ?', [$id, $key])->fetchColumn() >= 64) throw new \InvalidArgumentException('64 keys at most');
        $this->app->store->q('INSERT OR REPLACE INTO app_state(app, key, value) VALUES(?,?,?)', [$id, $key, $j]);
        return true;
    }

    /** The frame's page: the kit and the app's code, run under a nonce, in a sandbox of its own origin. */
    public function page(array $app, string $nonce): string
    {
        $kit = (string)file_get_contents(dirname(__DIR__) . '/web/public/appkit.js');
        $safe = fn(string $s) => str_ireplace('</script', '<\/script', $s);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($app['name']) . '</title>'
            . '<script nonce="' . $nonce . '">' . $safe($kit) . '</script></head><body>' . $app['html']
            . '<script nonce="' . $nonce . '">talixio.ready(async () => {' . "\n" . $safe($app['script']) . "\n});</script></body></html>";
    }

    /** The examples the person can add from the empty Apps page. */
    public const EXAMPLES = [
        'stocks' => ['name' => 'Stock prices', 'icon' => '📈', 'description' => 'Daily closing prices of a share over the last month (data from stooq.com).',
            'html' => '<header class="row"><h1>Stock prices</h1><form id="f" class="row"><input id="sym" value="aapl.us" aria-label="Symbol" size="10"><button>Show</button></form></header><div id="stats" class="stats"></div><div id="chart"></div><p class="muted small">Symbols as on stooq.com: aapl.us, msft.us, air.fr, ^cac…</p>',
            'script' => "const show = async sym => {\n  const rows = (await talixio.data('quotes', { symbol: sym })).filter(r => r.Close).slice(-22);\n  if (!rows.length) { talixio.message('#chart', 'No prices for ' + sym); return; }\n  const first = +rows[0].Close, last = +rows[rows.length - 1].Close;\n  talixio.stats('#stats', [{ label: 'Last close', value: talixio.num(last, 2) }, { label: 'Over the month', value: talixio.num((last / first - 1) * 100, 1) + ' %' }]);\n  talixio.chart('#chart', { type: 'line', labels: rows.map(r => r.Date), series: [{ name: sym.toUpperCase(), values: rows.map(r => +r.Close) }] });\n  talixio.save('symbol', sym);\n};\n$('#f').addEventListener('submit', e => { e.preventDefault(); show($('#sym').value.trim()); });\nconst sym = await talixio.load('symbol');\nif (sym) $('#sym').value = sym;\nshow($('#sym').value);",
            'sources' => [['name' => 'quotes', 'type' => 'web', 'url' => 'https://stooq.com/q/d/l/?s={symbol}&i=d', 'format' => 'csv', 'ttl' => 240]]],
        'tasks' => ['name' => 'Tasks at a glance', 'icon' => '✅', 'description' => "This space's open tasks by status and priority, and what is due soon.",
            'html' => '<h1>Tasks at a glance</h1><div id="stats" class="stats"></div><div class="grid"><section><h2>By status</h2><div id="status"></div></section><section><h2>By priority</h2><div id="prio"></div></section></div><h2>Due in the next 14 days</h2><div id="due"></div>',
            'script' => "const all = await talixio.data('tasks');\nconst open = all.filter(c => c.status !== 'done');\nconst soon = new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10);\nconst due = open.filter(c => c.due_date && c.due_date <= soon).sort((a, b) => a.due_date.localeCompare(b.due_date));\nconst count = (list, key, keys) => keys.map(k => list.filter(c => String(c[key]) === String(k)).length);\nconst st = ['idea', 'todo', 'doing', 'blocked'];\ntalixio.stats('#stats', [{ label: 'Open', value: open.length }, { label: 'Due soon', value: due.length }, { label: 'Done (30 days)', value: all.length - open.length }]);\ntalixio.chart('#status', { type: 'bar', labels: ['Ideas', 'To do', 'In progress', 'Blocked'], series: [{ name: 'Tasks', values: count(open, 'status', st) }] });\ntalixio.chart('#prio', { type: 'bar', labels: ['P1', 'P2', 'P3', 'P4', 'P5'], series: [{ name: 'Tasks', values: count(open, 'priority', [1, 2, 3, 4, 5]) }] });\ntalixio.table('#due', due.map(c => ({ Task: c.code + ' ' + c.title, Due: c.due_date, Priority: 'P' + c.priority })));",
            'sources' => [['name' => 'tasks', 'type' => 'tasks']]],
    ];
}
