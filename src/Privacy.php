<?php
declare(strict_types=1);
namespace Talixio;

/**
 * What leaves the server, and what may.
 *
 * Every request to the outside goes through Http, which calls outbound() first: the request is checked against the
 * confidentiality level of the work in progress, written to the journal (service, purpose, size: never the content)
 * and added to the trace of the current answer (shown under it in the app).
 *
 * Levels, per space: internal (as configured), confidential (only a model under business terms or a European provider
 * gets the content; transcription and search by meaning stay local or with the European provider, the web needs an approval each time, notifications say nothing),
 * secret (nothing leaves: a model on this server only). The level of the work is the space's, raised to the
 * strictest space whose documents or conversations it read (a General question that reads a confidential folder
 * becomes confidential from then on).
 */
final class Privacy
{
    public const LEVELS = ['internal', 'confidential', 'secret'];
    /** Tools that send something to the outside: an approval each time in a confidential space, never in a secret one. */
    public const OUTSIDE_TOOLS = ['web_search', 'fetch_url', 'browser_open', 'browser_read', 'browser_click', 'browser_type', 'browser_select', 'browser_back', 'browser_screenshot'];

    /** AI and search services outside Europe: refused when TALIXIO_SOVEREIGN_ONLY is on, whatever the level. */
    public const NOT_SOVEREIGN = ['anthropic', 'openai', 'gemini', 'voyage', 'brave', 'tavily'];

    /** What the current request is for: model, transcription, embeddings, search, web, notify, connector, calendar, chat, music. */
    public static string $purpose = 'model';
    /** Set while running an action the user approved: it may go out whatever the level. */
    public static bool $allowOnce = false;
    /** The strictest level of what the current work has read (index in LEVELS). */
    public static int $taint = 0;
    /** Set once the current work has read untrusted content (an email, a web page, a connector's result): from then on,
     *  lasting changes and requests to addresses not found in that content wait for the user's approval (Tools::run). */
    public static bool $untrusted = false;
    /** Set when that content came from the user's own data (emails, connectors), not only the public web. */
    public static bool $privateRead = false;
    /** @var array<string,true> the web addresses found in that content: opening one of them leaks nothing new */
    public static array $links = [];
    /** The current answer's trace: what went out, what stayed here, which documents, what was blocked. */
    public static ?array $trace = null;
    /** Told about each request as it leaves (live pills in the app). */
    public static $onEvent = null;

    private const SERVICES = [
        'anthropic' => ['name' => 'Anthropic (Claude API)', 'hosts' => ['api.anthropic.com'], 'terms' => 'https://www.anthropic.com/legal/commercial-terms'],
        'gemini' => ['name' => 'Google Gemini', 'hosts' => ['generativelanguage.googleapis.com'], 'terms' => 'https://ai.google.dev/gemini-api/terms'],
        'openai' => ['name' => 'OpenAI', 'hosts' => ['api.openai.com'], 'terms' => 'https://openai.com/policies/'],
        'voyage' => ['name' => 'Voyage AI', 'hosts' => ['api.voyageai.com'], 'terms' => ''],
        'brave' => ['name' => 'Brave Search', 'hosts' => ['api.search.brave.com'], 'terms' => ''],
        'tavily' => ['name' => 'Tavily', 'hosts' => ['api.tavily.com'], 'terms' => ''],
        'telegram' => ['name' => 'Telegram', 'hosts' => ['api.telegram.org'], 'terms' => ''],
        'google' => ['name' => 'Google (calendar, sign-in)', 'hosts' => ['www.googleapis.com', 'oauth2.googleapis.com', 'accounts.google.com'], 'terms' => ''],
        'spotify' => ['name' => 'Spotify', 'hosts' => ['api.spotify.com', 'accounts.spotify.com'], 'terms' => ''],
        'microsoft' => ['name' => 'Microsoft (Outlook calendar, sign-in)', 'hosts' => ['graph.microsoft.com', 'login.microsoftonline.com'], 'terms' => ''],
        'push' => ['name' => 'Web push (encrypted)', 'hosts' => ['fcm.googleapis.com', '.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'], 'terms' => ''],
    ];

    public function __construct(private App $app) {}

    public static function rank(string $level): int { return max(0, (int)array_search($level, self::LEVELS, true)); }

    // ---- levels ----

    public function spaceLevel(int $space): string
    {
        $l = $space ? (string)($this->app->spaces()->get($space)['privacy'] ?? 'internal') : 'internal';
        return in_array($l, self::LEVELS, true) ? $l : 'internal';
    }

    /** The level of a library folder: the strictest space whose folder contains it. */
    public function folderLevel(string $folder): string
    {
        $r = 0;
        foreach ($this->strictSpaces() as $s) {
            if ($s['folder'] !== '' && ($folder === $s['folder'] || str_starts_with($folder, rtrim($s['folder'], '/') . '/'))) $r = max($r, self::rank($s['privacy']));
        }
        return self::LEVELS[$r];
    }

    /** Spaces above "internal" (few; read once per process and request). */
    public function strictSpaces(): array
    {
        static $cache = [];
        $key = spl_object_id($this->app->store) . ':' . $this->app->store->get('privacy_rev', '0');
        return $cache[$key] ??= array_map(fn($s) => ['id' => (int)$s['id'], 'folder' => (string)$s['folder'], 'privacy' => (string)$s['privacy'], 'name' => (string)$s['name']],
            $this->app->store->q("SELECT id, folder, privacy, name FROM spaces WHERE privacy IN ('confidential','secret')")->fetchAll());
    }

    /** The level of an indexed piece of memory (docs row): its space's, or its file's folder's. */
    public function docLevel(array $d): string
    {
        if (in_array($d['kind'], ['file', 'transcript'], true)) {
            if (!$this->strictSpaces()) return 'internal';
            $folder = $this->app->store->q('SELECT folder FROM files WHERE id = ?', [(int)$d['ref']])->fetchColumn();
            return $folder === false ? 'internal' : $this->folderLevel((string)$folder);
        }
        return $this->spaceLevel((int)($d['space'] ?? 0));
    }

    /** Secret content is only ever read inside its own space. */
    public function hidden(string $level, ?int $space = null, ?string $folder = null): bool
    {
        if ($level !== 'secret') return false;
        $cur = $this->app->space ? $this->app->spaces()->get($this->app->space) : null;
        if (!$cur || ($cur['privacy'] ?? '') !== 'secret') return true;
        if ($space !== null) return $space !== (int)$cur['id'];
        return $folder === null || $cur['folder'] === '' || !($folder === $cur['folder'] || str_starts_with($folder, rtrim($cur['folder'], '/') . '/'));
    }

    /**
     * Whether content of this level may be brought into the current work: not hidden (secret, other space), and the model
     * doing the work may see it (a General model under consumer terms never reads a Confidential space's documents, even
     * when it runs its tools elsewhere). A person searching in the app only gets the first check.
     */
    public function mayRead(string $level, ?int $space = null, ?string $folder = null): bool
    {
        if ($this->hidden($level, $space, $folder)) return false;
        return $level === 'internal' || self::$person || in_array($this->modelBlocked($level), [null, 'sovereign-only'], true);
    }

    /** True while the app serves a person's own search (not a model's). */
    public static bool $person = false;

    public static function forPerson(callable $fn): mixed
    {
        $was = self::$person; self::$person = true;
        try { return $fn(); } finally { self::$person = $was; }
    }

    /**
     * Why content of this level may not be written into this library folder (null: it may). Nothing goes down: what comes
     * from a Confidential space (or a turn that read Confidential content) is not saved where a less protected model
     * could read it, and nothing is written into a Secret space from outside it.
     */
    public function writeDown(string $folder, string $level): ?string
    {
        $dest = $this->folderLevel($folder);
        if ($this->hidden($dest, null, $folder)) return "$folder belongs to a Secret space: nothing is written there from elsewhere";
        if (self::rank($dest) < self::rank($level)) return "this comes from " . ucfirst($level) . " content and $folder is " . ($dest === 'internal' ? 'not protected' : ucfirst($dest))
            . ': it stays at its level (save it in the folder of a space at least as protected)';
        return null;
    }

    /** Library hits ({folder, name?, snippet?}) the current work may read; the rest of the work follows their level. */
    public function readable(array $hits): array
    {
        $out = [];
        foreach ($hits as $h) {
            $lvl = $this->folderLevel((string)$h['folder']);
            if (!$this->mayRead($lvl, null, (string)$h['folder'])) continue;
            if (isset($h['name'])) self::doc((string)$h['name'], mb_strlen((string)($h['snippet'] ?? '')), $lvl); else self::raise($lvl);
            $out[] = $h;
        }
        return $out;
    }

    /** Doc ids (docs table) that must not be sent to an embeddings service outside: confidential and secret content (secret only for a European provider). */
    public function noOutsideEmbedding(): array
    {
        $strict = $this->strictSpaces();
        if ($this->app->embedCfg()->embeddings === 'sovereign') $strict = array_values(array_filter($strict, fn($s) => $s['privacy'] === 'secret'));
        if (!$strict) return [];
        $ids = [];
        $spaceIds = array_column($strict, 'id');
        $ids = array_merge($ids, array_column($this->app->store->q("SELECT id FROM docs WHERE kind IN ('message','summary') AND space IN (" . implode(',', array_map('intval', $spaceIds)) . ')')->fetchAll(), 'id'));
        foreach ($strict as $s) {
            if ($s['folder'] === '') continue;
            $files = $this->app->library()->fileIds($s['folder']);
            if ($files) $ids = array_merge($ids, array_column($this->app->store->q("SELECT id FROM docs WHERE kind IN ('file','transcript') AND ref IN (" . implode(',', $files) . ')')->fetchAll(), 'id'));
        }
        return array_map('intval', $ids);
    }

    /** The level the current work runs at. */
    public function effective(): string { return self::LEVELS[max(self::rank($this->spaceLevel($this->app->space)), self::$taint)]; }

    /** The work has read something of this level: everything after it follows that level's rules. */
    public static function raise(string $level): void { self::$taint = max(self::$taint, self::rank($level)); }

    /** Run $fn for a purpose (transcription, embeddings...). */
    public static function as(string $purpose, callable $fn): mixed
    {
        $before = self::$purpose; self::$purpose = $purpose;
        try { return $fn(); } finally { self::$purpose = $before; }
    }

    /** Run $fn as an action the user approved. */
    public static function approved(callable $fn): mixed
    {
        $before = self::$allowOnce; self::$allowOnce = true;
        try { return $fn(); } finally { self::$allowOnce = $before; }
    }

    /** A new piece of work: nothing untrusted read yet. */
    public static function trustAgain(): void { self::$untrusted = false; self::$privateRead = false; self::$links = []; }

    // ---- the trace of one answer ----

    public static function begin(): void { self::$taint = 0; self::trustAgain(); self::$trace = ['out' => [], 'local' => [], 'docs' => [], 'blocked' => []]; }

    /** The trace collected since begin(), for the answer's meta (null when nothing was recorded). */
    public static function end(): ?array
    {
        $t = self::$trace; self::$trace = null; self::$onEvent = null;
        if (!$t) return null;
        $t['out'] = array_values($t['out']);
        $t['docs'] = array_values($t['docs']);
        $t['local'] = array_values(array_unique($t['local']));
        $t['blocked'] = array_values(array_unique($t['blocked']));
        return $t['out'] || $t['docs'] || $t['local'] || $t['blocked'] ? $t : null;
    }

    /** Something done on this server (library search, local model...). */
    public static function local(string $label): void
    {
        if (self::$trace === null) return;
        self::$trace['local'][] = $label;
        if (self::$onEvent) (self::$onEvent)(['type' => 'privacy', 'local' => true, 'label' => $label]);
    }

    /** A document (or part of one) given to the model. */
    public static function doc(string $name, int $chars, string $level = 'internal'): void
    {
        self::raise($level);
        if (self::$trace === null) return;
        $d = self::$trace['docs'][$name] ?? ['name' => $name, 'chars' => 0];
        $d['chars'] += $chars;
        self::$trace['docs'][$name] = $d;
    }

    public static function blocked(string $label): void { if (self::$trace !== null) self::$trace['blocked'][] = $label; }

    // ---- requests to the outside ----

    /** @return array{id:string,name:string,local:bool,host:string} */
    public function service(string $url): array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $c = $this->app->cfg;
        if (self::isLocalHost($host)) return ['id' => 'local', 'name' => $host === parse_url($c->ollamaUrl, PHP_URL_HOST) && (parse_url($url, PHP_URL_PORT) ?: 80) === (parse_url($c->ollamaUrl, PHP_URL_PORT) ?: 80) ? 'Ollama' : 'this server', 'local' => true, 'host' => $host];
        if ($eu = Sovereign::serviceOf($url)) return ['id' => $eu, 'name' => Sovereign::SERVICES[$eu]['name'], 'local' => false, 'host' => $host];
        foreach (self::SERVICES as $id => $s) {
            foreach ($s['hosts'] as $h) if ($host === $h || (str_starts_with($h, '.') && str_ends_with($host, $h))) return ['id' => $id, 'name' => $s['name'], 'local' => false, 'host' => $host];
        }
        if ($c->ntfyUrl !== '' && $host === strtolower((string)parse_url($c->ntfyUrl, PHP_URL_HOST))) return ['id' => 'ntfy', 'name' => "ntfy ($host)", 'local' => false, 'host' => $host];
        if ($c->searxngUrl !== '' && $host === strtolower((string)parse_url($c->searxngUrl, PHP_URL_HOST))) return ['id' => 'searxng', 'name' => "SearXNG ($host)", 'local' => false, 'host' => $host];
        foreach ([$c->anthropicBaseUrl, $c->openaiBaseUrl] as $base) {
            if ($host === strtolower((string)parse_url($base, PHP_URL_HOST))) return ['id' => 'model:' . $host, 'name' => $host, 'local' => false, 'host' => $host];
        }
        foreach ($this->app->mcp()->servers() as $name => $s) {
            if (isset($s['url']) && $host === strtolower((string)parse_url((string)$s['url'], PHP_URL_HOST))) return ['id' => "mcp:$name", 'name' => "Connector $name", 'local' => false, 'host' => $host];
        }
        return ['id' => 'web', 'name' => $host, 'local' => false, 'host' => $host];
    }

    public static function isLocalHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || !str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)) return true; // docker service names
        if (filter_var($host, FILTER_VALIDATE_IP)) return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return false;
    }

    /** The service the conversation model is reached at. */
    public function modelService(): string
    {
        $c = $this->app->cfg;
        return match ($c->provider) {
            'anthropic' => $this->service($c->anthropicBaseUrl ?: 'https://api.anthropic.com')['id'],
            'openai', 'gemini', 'sovereign' => $this->service($c->openaiBaseUrl)['id'],
            'claude-code' => 'anthropic',
            default => 'local',
        };
    }

    /**
     * How the conversation model treats your data: local (this server), business (API under business terms, no training
     * on your data), sovereign (a European provider: business terms, in Europe, under European law), free (may be used to improve the provider's models), consumer (a personal subscription), unknown.
     */
    public function modelGrade(): string
    {
        $c = $this->app->cfg;
        $svc = $this->modelService();
        return match (true) {
            $c->provider === 'echo' || $svc === 'local' => 'local',
            $c->provider === 'claude-code' => 'consumer',
            self::isSovereign($svc) => 'sovereign',
            $c->provider === 'gemini' => $c->geminiPaid ? 'business' : 'free',
            in_array($svc, ['anthropic', 'openai'], true) => 'business',
            default => 'unknown',
        };
    }

    /** Why the model may not be used at this level (null: it may). */
    public function modelBlocked(string $level): ?string
    {
        $g = $this->modelGrade();
        if ($level === 'secret' && $g !== 'local') return 'secret';
        if ($level === 'confidential' && !in_array($g, ['local', 'business', 'sovereign'], true)) return 'confidential';
        if ($this->app->cfg->sovereignOnly && in_array($this->modelService(), self::NOT_SOVEREIGN, true)) return 'sovereign-only';
        return null;
    }

    public static function isSovereign(string $serviceId): bool { return isset(Sovereign::SERVICES[$serviceId]); }

    /** Called by Http before every request to the outside. Throws PrivacyBlocked when the level forbids it. */
    public function outbound(string $url, int $bytes): void
    {
        $svc = $this->service($url);
        $purpose = self::$purpose;
        if ($purpose === 'model' && !$svc['local'] && $svc['id'] !== $this->modelService()) { // not the model: what this service is for
            $purpose = match (true) {
                $svc['id'] === 'telegram' => 'chat', $svc['id'] === 'google' || $svc['id'] === 'microsoft' => 'calendar', $svc['id'] === 'spotify' => 'music',
                in_array($svc['id'], ['push', 'ntfy'], true) => 'notify', in_array($svc['id'], ['brave', 'tavily', 'searxng'], true) => 'search',
                str_starts_with($svc['id'], 'mcp:') => 'connector', $svc['id'] === 'web' => 'web', default => 'other',
            };
        }
        if ($svc['local']) {
            if ($purpose !== 'model' || $svc['name'] === 'Ollama') self::local($svc['name'] === 'Ollama' ? 'Ollama' : ucfirst($purpose));
            return;
        }
        if ($this->app->cfg->sovereignOnly && in_array($svc['id'], self::NOT_SOVEREIGN, true)) { // no approval opens it
            $this->log($svc['id'], $purpose, $bytes, $this->effective(), true);
            self::blocked(self::label($svc, $purpose));
            throw new PrivacyBlocked('blocked: only European providers may be used (Settings → AI model): ' . self::label($svc, $purpose) . ' would send data to ' . $svc['name']);
        }
        $level = $this->effective();
        $allowed = self::$allowOnce || $purpose === 'notify' // notifications say nothing at these levels
            || $purpose === 'billing' // reads the provider's own cost figures, sends no content
            || $level === 'internal'
            || ($level === 'confidential' && in_array($purpose, ['model', 'vision'], true) && $svc['id'] === $this->modelService() && $this->modelBlocked('confidential') === null)
            || ($level === 'confidential' && in_array($purpose, ['embeddings', 'transcription'], true) && self::isSovereign($svc['id'])); // stays in Europe, business terms
        $this->log($svc['id'], $purpose, $bytes, $level, !$allowed);
        if (!$allowed) {
            self::blocked(self::label($svc, $purpose));
            throw new PrivacyBlocked("blocked by the space's confidentiality level ($level): " . self::label($svc, $purpose) . ' would send data to ' . $svc['name']);
        }
        if (self::$trace !== null) {
            $k = $svc['id'] . '|' . $purpose;
            $o = self::$trace['out'][$k] ?? ['service' => $svc['name'], 'purpose' => $purpose, 'calls' => 0, 'bytes' => 0];
            $o['calls']++; $o['bytes'] += $bytes;
            self::$trace['out'][$k] = $o;
        }
        if (self::$onEvent) (self::$onEvent)(['type' => 'privacy', 'local' => false, 'label' => $svc['name'], 'purpose' => $purpose]);
    }

    private static function label(array $svc, string $purpose): string
    {
        return ['search' => 'Web search', 'web' => 'Reading a web page', 'transcription' => 'Transcription', 'embeddings' => 'Search by meaning',
            'connector' => 'Connector', 'calendar' => 'Calendar', 'music' => 'Music', 'chat' => 'Chat app', 'model' => 'Model', 'vision' => 'Reading images', 'billing' => 'Reading costs'][$purpose] ?? ucfirst($purpose);
    }

    private function log(string $service, string $purpose, int $bytes, string $level, bool $blocked): void
    {
        try {
            $this->app->store->q('INSERT INTO egress(ts, service, purpose, bytes, space, level, blocked) VALUES(?,?,?,?,?,?,?)',
                [time(), $service, $purpose, $bytes, $this->app->space, $level, $blocked ? 1 : 0]);
        } catch (\Throwable $e) { Log::warn("egress log: {$e->getMessage()}"); }
    }

    /** Old journal lines go after 180 days. */
    public function purge(): int { return $this->app->store->q('DELETE FROM egress WHERE ts < ?', [time() - 180 * 86400])->rowCount(); }

    // ---- tools ----

    /**
     * Before a tool runs: null = go ahead. Otherwise what the model is told: in a secret space outside tools don't
     * run; in a confidential one they wait for the user's approval (each time).
     */
    public function toolGate(string $name, array $args): ?string
    {
        if (self::$allowOnce) return null;
        // with internet in the sandbox, a command can send anything it reads: it is an outside tool too
        $outside = in_array($name, self::OUTSIDE_TOOLS, true) || str_starts_with($name, 'mcp_')
            || in_array($name, ['shell_run', 'script_run'], true) && $this->app->cfg->shellNetwork;
        if (!$outside) return null;
        $level = $this->effective();
        if ($level === 'internal') return null;
        if ($level === 'confidential' && $this->trusted($name, $args)) return null; // the user allowed this site (or search) for good
        $what = match (true) {
            $name === 'web_search' => 'Web search: "' . mb_substr((string)($args['query'] ?? ''), 0, 200) . '"',
            $name === 'fetch_url' || $name === 'browser_open' => 'Open the web page ' . mb_substr((string)($args['url'] ?? ''), 0, 300),
            str_starts_with($name, 'mcp_') => "Use the connector tool $name",
            $name === 'shell_run' => 'Run in the sandbox with internet: ' . mb_substr((string)($args['command'] ?? ''), 0, 300),
            $name === 'script_run' => 'Run the script "' . mb_substr((string)($args['name'] ?? ''), 0, 100) . '" in the sandbox with internet',
            default => 'Use the web browser (' . str_replace('browser_', '', $name) . ')',
        };
        self::blocked($what);
        if ($level === 'secret') return "error: blocked: this is a Secret space, nothing may leave the server ($what). Answer with what is on the server only.";
        if (App::$inWorker || $this->app->jobId) return "error: blocked: this is a Confidential space and outside access needs the user's approval, which can't be awaited here ($what). Answer with what is on the server only.";
        return $this->app->approvals()->request($name, $args, "$what (from a Confidential space)")
            . ' The user may approve it once; meanwhile answer with what is on the server only, and say that the web was not used.';
    }

    /** A page on a site the user trusts, or a search once they allowed it from Confidential spaces. */
    public function trusted(string $name, array $args): bool
    {
        $c = $this->app->cfg;
        return in_array($name, ['fetch_url', 'browser_open'], true) && $c->trustedSite((string)($args['url'] ?? ''))
            || $name === 'web_search' && $c->webSearchTrusted;
    }

    // ---- the Privacy page ----

    /** Each service data can go to with this configuration, how it treats it, and what would make it safer. */
    public function flows(): array
    {
        $c = $this->app->mainCfg; $out = [];
        $v = $this->app->voiceCfg(); $ec = $this->app->embedCfg(); // what transcription and search by meaning really run on (the routing may differ from the main settings)
        $add = function (string $id, string $name, string $what, string $status, string $why, array $fix = [], string $terms = '') use (&$out) {
            $out[] = compact('id', 'name', 'what', 'status', 'why', 'fix', 'terms');
        };
        $whisper = Transcriber::whisper($c);
        $whisperReady = $whisper[0] !== '' && is_file($whisper[1]);

        // the model
        $g = $this->modelGrade(); $svc = $this->modelService();
        $mname = $c->provider === 'claude-code' ? 'Claude (Pro/Max subscription)' : ($svc === 'local' ? 'Model on this server' : (self::SERVICES[$svc]['name'] ?? Sovereign::SERVICES[$svc]['name'] ?? substr($svc, 6)));
        $eu = fn(string $id) => trim('European provider (' . Sovereign::SERVICES[$id]['country'] . '): business terms, processed in Europe, not subject to the US CLOUD Act. ' . Sovereign::SERVICES[$id]['note']);
        $terms = fn(string $id) => self::SERVICES[$id]['terms'] ?? Sovereign::SERVICES[$id]['terms'] ?? '';
        $what = 'Your messages, the documents and memories it reads, images and scanned pages to describe.';
        match ($g) {
            'local' => $add('model', $mname, $what, 'local', 'Runs on this server.'),
            'sovereign' => $add('model', $mname, $what, 'good', $eu($svc), [], $terms($svc)),
            'business' => $add('model', $mname, $what, 'good', 'Business terms: your data is not used to train models.', [], $terms($svc)),
            'free' => $add('model', $mname, $what, 'bad', 'Free tier: Google may use what you send to improve its products.', [['label' => 'My Gemini key is on a paid plan', 'set' => ['TALIXIO_GEMINI_PAID' => '1']]], $terms('gemini')),
            'consumer' => $add('model', $mname, $what, 'bad', 'Personal subscription: consumer terms, not a business contract. Use the Claude API for work data.', [['label' => 'Choose the provider', 'href' => '#settings/brain']], 'https://www.anthropic.com/legal/consumer-terms'),
            default => $add('model', $mname, $what, 'warn', "Check this provider's terms (training, retention, location)."),
        };

        // transcription
        if ($v->transcribe === 'local') $add('transcription', 'Transcription (whisper.cpp)', 'Voice notes and meeting recordings.', 'local', 'Runs on this server.');
        elseif ($v->transcribe === 'gemini') $add('transcription', 'Transcription (Google Gemini)', 'Voice notes and meeting recordings.', $v->geminiPaid ? 'good' : 'bad',
            $v->geminiPaid ? 'Paid plan: not used to train models.' : 'Free tier: Google may use the recordings to improve its products.',
            $whisperReady ? [['label' => 'Transcribe on this server', 'set' => ['TALIXIO_TRANSCRIBE' => 'local']]] : [['label' => 'My Gemini key is on a paid plan', 'set' => ['TALIXIO_GEMINI_PAID' => '1']]], $terms('gemini'));
        elseif ($v->transcribe === 'openai') $add('transcription', 'Transcription (OpenAI)', 'Voice notes and meeting recordings.', 'good', 'API terms: not used to train models.', [], $terms('openai'));
        elseif ($v->transcribe === 'sovereign') $add('transcription', 'Transcription (' . Sovereign::info($v->sovereign)['name'] . ')', 'Voice notes and meeting recordings.', 'good', $eu($v->sovereign), [], $terms($v->sovereign));

        // search by meaning
        $e = $ec->embeddings;
        if ($e === 'ollama') $add('embeddings', 'Search by meaning (Ollama)', 'Text of your memory and documents, to index them.', 'local', 'Runs on this server.');
        elseif ($e === 'gemini') $add('embeddings', 'Search by meaning (Google Gemini)', 'Text of your memory and documents, to index them.', $ec->geminiPaid ? 'good' : 'bad',
            $ec->geminiPaid ? 'Paid plan: not used to train models.' : 'Free tier: Google may use the text to improve its products.', [['label' => 'Use Ollama on this server', 'href' => '#settings/memory']], $terms('gemini'));
        elseif ($e === 'sovereign') $add('embeddings', 'Search by meaning (' . Sovereign::info($ec->sovereign)['name'] . ')', 'Text of your memory and documents, to index them.', 'good', $eu($ec->sovereign), [], $terms($ec->sovereign));
        elseif (in_array($e, ['openai', 'voyage'], true)) $add('embeddings', 'Search by meaning (' . ($e === 'openai' ? 'OpenAI' : 'Voyage AI') . ')', 'Text of your memory and documents, to index them.', 'good', 'API terms: not used to train models.', [], $terms($e));

        // web search
        $s = $c->search;
        if ($s !== 'off') {
            $sname = ['builtin' => 'Web search (the model\'s own)', 'brave' => 'Web search (Brave)', 'tavily' => 'Web search (Tavily)', 'searxng' => 'Web search (SearXNG)', 'gemini' => 'Web search (Google)'][$s] ?? 'Web search';
            $add('search', $sname, 'Search queries, and the pages it reads.', $s === 'gemini' && !$c->geminiPaid ? 'warn' : 'good',
                'Queries only, never your documents. Blocked in Confidential spaces unless you approve it.');
        }

        // notifications and chat apps
        $neutral = $c->notifyNeutral;
        foreach ($c->notify as $ch) {
            if ($ch === 'telegram' && $c->telegramToken !== '') $add('telegram', 'Telegram', 'Notifications' . ($c->telegramAllowed ? ' and the conversations you have there' : '') . '.', $neutral ? 'warn' : 'bad',
                ($neutral ? 'Notifications say nothing. ' : 'Telegram can read the messages (not end-to-end encrypted). ') . 'Confidential spaces cannot be used from Telegram.',
                $neutral ? [] : [['label' => 'Notifications without content', 'set' => ['TALIXIO_NOTIFY_NEUTRAL' => '1']]]);
            if ($ch === 'ntfy' && $c->ntfyUrl !== '') {
                $public = str_contains(strtolower((string)parse_url($c->ntfyUrl, PHP_URL_HOST)), 'ntfy.sh');
                $add('ntfy', 'ntfy', 'Notifications.', $neutral || !$public ? 'good' : 'bad', $public ? 'The public ntfy.sh server can read notifications, and anyone who knows the topic too.' : 'Your own ntfy server.',
                    $neutral || !$public ? [] : [['label' => 'Notifications without content', 'set' => ['TALIXIO_NOTIFY_NEUTRAL' => '1']]]);
            }
            if ($ch === 'email' && $this->app->email()->canSend()) $add('email', 'Email notifications', 'Notifications, through your mail provider.', $neutral ? 'good' : 'warn', 'Your mail provider stores them like any email.',
                $neutral ? [] : [['label' => 'Notifications without content', 'set' => ['TALIXIO_NOTIFY_NEUTRAL' => '1']]]);
            if ($ch === 'signal' && $this->app->signal()->enabled()) $add('signal', 'Signal', 'Notifications and conversations.', 'good', 'End-to-end encrypted.');
            if ($ch === 'webpush' && $this->app->webPush()->count() > 0) $add('push', 'App notifications', 'Notifications.', 'good', 'End-to-end encrypted to your devices.');
        }

        // connectors and other accounts
        foreach ($this->app->mcp()->servers() as $name => $srv) {
            $add("mcp:$name", "Connector $name", 'What the assistant sends to its tools.', 'warn', isset($srv['url']) ? 'A service outside: check its terms. Needs your approval in Confidential spaces.' : 'Runs on this server, but may reach the internet. Needs your approval in Confidential spaces.', [['label' => 'Connectors', 'href' => '#settings/connectors']]);
        }
        if ($this->app->calendar()->enabled() && $this->app->google()->connected()) $add('google', 'Google Calendar', 'Events the assistant reads and adds.', 'good', 'Google already holds your calendar.');
        if ($this->app->calendar()->enabled() && $this->app->microsoft()->connected()) $add('microsoft', 'Outlook calendar', 'Events the assistant reads and adds.', 'good', 'Microsoft already holds your calendar.');

        // shared links
        $active = $this->app->shares()->list('active');
        if ($active) {
            $open = count(array_filter($active, fn($x) => !$x['password']));
            $add('shares', 'Shared links', count($active) . ' active link(s) to your documents.', $open ? 'warn' : 'good',
                $open ? "$open of them without a password: anyone with the link can open it." : 'All protected by a password.', [['label' => 'See the shares', 'href' => '#library/shares']]);
        }
        return $out;
    }

    /** The overall level (local, business, personal), with what keeps it from the next one. */
    public function overview(): array
    {
        $flows = $this->flows();
        $outside = array_filter($flows, fn($f) => $f['status'] !== 'local');
        $issues = array_values(array_filter($flows, fn($f) => in_array($f['status'], ['bad', 'warn'], true) && ($f['status'] === 'bad' || $f['fix'])));
        $level = !$outside ? 'local' : (array_filter($flows, fn($f) => $f['status'] === 'bad') ? 'personal' : 'business');
        $v = $this->app->voiceCfg(); $ec = $this->app->embedCfg();
        $ai = array_filter([$this->modelService(), $v->transcribe === 'sovereign' ? $v->sovereign : $v->transcribe, $ec->embeddings === 'sovereign' ? $ec->sovereign : $ec->embeddings],
            fn($x) => !in_array($x, ['local', 'off', 'ollama'], true));
        $european = $level !== 'personal' && !array_filter($ai, fn($x) => !self::isSovereign($x)); // every AI service is local or European
        $auth = new Auth($this->app);
        $security = [
            ['label' => 'HTTPS', 'ok' => str_starts_with($this->app->shares()->baseUrl(), 'https://') || $this->app->shares()->baseUrl() === ''],
            ['label' => 'Two-step sign-in', 'ok' => $auth->totpEnabled()],
            ['label' => 'Nightly backups', 'ok' => $this->app->cfg->backupKeep > 0],
        ];
        $spaces = array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['id'] ? $s['name'] : 'General', 'level' => $s['privacy'] ?? 'internal', 'color' => $s['color']], $this->app->spaces()->all());
        return ['level' => $level, 'european' => $european, 'sovereign_only' => $c->sovereignOnly, 'model_grade' => $this->modelGrade(), 'flows' => $flows, 'issues' => count($issues), 'security' => $security, 'spaces' => $spaces];
    }

    /** The journal: requests per service, today and over $days days, and per space. */
    public function journal(int $days = 7): array
    {
        $st = $this->app->store;
        $since = strtotime('today');
        $sum = fn(int $from) => $st->q('SELECT service, purpose, COUNT(*) AS calls, SUM(bytes) AS bytes, SUM(blocked) AS blocked FROM egress WHERE ts >= ? GROUP BY service, purpose ORDER BY calls DESC', [$from])->fetchAll();
        $names = fn(array $rows) => array_map(fn($r) => $r + ['name' => self::SERVICES[$r['service']]['name'] ?? (str_starts_with($r['service'], 'mcp:') ? 'Connector ' . substr($r['service'], 4) : (str_starts_with($r['service'], 'model:') ? substr($r['service'], 6) : $r['service']))], $rows);
        $spaces = $st->q("SELECT space, COUNT(*) AS calls, SUM(blocked) AS blocked, GROUP_CONCAT(DISTINCT service) AS services FROM egress WHERE ts >= ? GROUP BY space", [time() - $days * 86400])->fetchAll();
        return ['today' => $names($sum($since)), 'week' => $names($sum(time() - $days * 86400)), 'spaces' => $spaces];
    }

    /** Every request of the last $days days, for an export (CSV). */
    public function journalRows(int $days = 90): array
    {
        return $this->app->store->q('SELECT ts, service, purpose, bytes, space, level, blocked FROM egress WHERE ts >= ? ORDER BY id', [time() - $days * 86400])->fetchAll();
    }

    /** A record of processing (GDPR art. 30) to start from: the processors this configuration uses. Markdown. */
    public function register(): string
    {
        $c = $this->app->cfg;
        $lines = ["# Record of processing: personal assistant {$c->name}", '', 'Generated on ' . date('Y-m-d') . ' from the configuration. To be completed and checked (controller, legal basis, people concerned).', '',
            '## Processing', '', '- Purpose: personal and professional assistant (messages, documents, email, calendar, tasks, meeting summaries).',
            '- Hosting: your own server' . ($this->app->shares()->baseUrl() !== '' ? ' (' . parse_url($this->app->shares()->baseUrl(), PHP_URL_HOST) . ')' : '') . '. Conversations, documents and memory are stored there.',
            '- Retention: conversations and documents until you delete them; journal of outside requests 180 days; access logs of shared links 90 days after the link ends.', '',
            '## Recipients and processors', '', '| Service | Data | Terms |', '|---|---|---|'];
        foreach ($this->flows() as $f) {
            if ($f['status'] === 'local') continue;
            $lines[] = "| {$f['name']} | {$f['what']} | " . ($f['terms'] ?: '-') . ' |';
        }
        $strict = array_filter($this->overview()['spaces'], fn($s) => $s['level'] !== 'internal');
        if ($strict) {
            $lines[] = ''; $lines[] = '## Spaces with stricter rules'; $lines[] = '';
            foreach ($strict as $s) $lines[] = "- {$s['name']}: " . ($s['level'] === 'secret' ? 'Secret (nothing leaves the server)' : 'Confidential (only a business-terms or European model receives content)');
        }
        return implode("\n", $lines) . "\n";
    }
}
