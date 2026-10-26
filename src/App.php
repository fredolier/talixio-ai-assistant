<?php
declare(strict_types=1);
namespace Talixio;

/** Wires the pieces together. */
final class App
{
    public Store $store;
    public Workspace $ws;
    public Tools $tools;
    public Embeddings $embeddings;
    /** True inside the long-running worker (it must not block waiting for approvals). */
    public static bool $inWorker = false;
    /** The space the current conversation turn belongs to (0 = General). */
    public int $space = 0;
    /** The channel of the conversation being answered: approvals it raises are answered by a bare "yes" there only. */
    public string $channel = '';
    /** Told to the model with the next tool result (e.g. a background job nearing its cost cap). */
    public string $notice = '';
    /** The background job this process is running (its tool steps are kept so it can be continued). */
    public ?int $jobId = null;
    private ?Board $board = null;
    private ?Privacy $privacy = null;
    /** Set in tests to replace the configured model. */
    public ?Provider $providerOverride = null;
    /** Set in tests to replace the Claude Code CLI the conductor drives (FakeClaudeCli). */
    public ?ClaudeCli $claudeCliOverride = null;

    /** The main settings; $cfg is the current space's AI account when it has one (setSpace). */
    public Config $mainCfg;
    /** @var array<string,Config> */ private array $accountCfg = [];

    public function __construct(public Config $cfg)
    {
        $this->mainCfg = $cfg;
        $this->store = new Store($cfg->dbPath());
        if ($this->store->get('data_dir', '') !== $cfg->dataDir) $this->relocate(); // the data was moved (Docker, another server)
        if ($cfg->provider === 'gemini') GeminiModels::apply($cfg, $this->store);
        if ($cfg->provider === 'sovereign') Sovereign::apply($cfg, $this->store);
        $this->ws = new Workspace($cfg->workspaceDir());
        $this->embeddings = new Embeddings($cfg);
        $this->tools = new Tools($this);
        Content::$imageLoader = fn(int $id) => $this->files()->imagePart($id);
        Http::$hook = fn(string $url, int $bytes) => $this->privacy()->outbound($url, $bytes); // every request to the outside is checked and logged
    }

    /**
     * The files are recorded with their full path; when the data folder moves (install.sh → Docker, a transfer to
     * another server), point them at where they are now. Once per data folder.
     */
    private function relocate(): void
    {
        $st = $this->store; $dir = $this->cfg->filesDir(); $n = 0;
        foreach ($st->q("SELECT id, path FROM files WHERE path != '' AND path NOT LIKE ? ESCAPE '\\'", [addcslashes($dir, '%_\\') . '/%'])->fetchAll() as $f) {
            if (!preg_match('#/files/(\d{4}/\d{2}/[^/]+)$#', (string)$f['path'], $m) || !is_file("$dir/{$m[1]}")) continue;
            $st->q('UPDATE files SET path = ? WHERE id = ?', ["$dir/{$m[1]}", $f['id']]);
            $n++;
        }
        if ($n) Log::info("data folder moved to {$this->cfg->dataDir}: $n file path(s) updated");
        $st->set('data_dir', $this->cfg->dataDir);
    }

    /** Work within a space: its tools, its confidentiality level and its AI account. */
    public function setSpace(int $space): void
    {
        $this->space = $space;
        $this->cfg = $this->configFor($space);
    }

    /** The configuration a space's model calls use: the main settings, or its AI account's. */
    public function configFor(int $space): Config
    {
        $id = $this->spaces()->accountOf($space);
        if ($id === '') return $this->mainCfg;
        if (isset($this->accountCfg[$id])) return $this->accountCfg[$id];
        $a = $this->accounts()->get($id, true);
        if (!$a) return $this->mainCfg; // deleted meanwhile
        $c = $this->mainCfg->forAccount($a);
        if ($c->provider === 'gemini') GeminiModels::apply($c, $this->store, "gemini_models_$id");
        if ($c->provider === 'sovereign') Sovereign::apply($c, $this->store, "sovereign_models_$id");
        return $this->accountCfg[$id] = $c;
    }

    /** Forget the account configurations built so far (an account was changed). */
    public function accountsChanged(): void { $this->accountCfg = []; $this->cfg = $this->configFor($this->space); }

    public static function fromEnv(): self { return new self(Config::fromEnv()); }

    public function provider(Tier $tier): Provider
    {
        if ($this->providerOverride) return $this->providerOverride;
        $c = $this->cfg;
        return match ($c->provider) {
            'anthropic' => new AnthropicProvider($c->anthropicKey, $tier, $c->search === 'builtin' && $this->privacy()->effective() === 'internal', $c->anthropicBaseUrl), // the model's own search can't be checked here
            'openai', 'gemini' => new OpenAIProvider($c->openaiKey, $tier, $c->openaiBaseUrl),
            'sovereign' => new OpenAIProvider($c->openaiKey, $tier, $c->openaiBaseUrl, $c->sovereign === 'mistral'), // Mistral refuses stream_options
            'claude-code' => new ClaudeCodeProvider($c->claudeBin, $tier, $c->search === 'builtin' && $this->privacy()->effective() === 'internal', dirname(__DIR__) . '/bin/talixio', $c->claudeToken),
            'echo' => new EchoProvider(),
            default => throw new \InvalidArgumentException("unknown TALIXIO_PROVIDER {$c->provider}"),
        };
    }

    public function agent(): Agent { return new Agent($this); }
    public function jobs(): Jobs { return new Jobs($this); }
    public function recall(): Recall { return new Recall($this->store, $this->embeddings, $this); }
    public function budget(): Budget
    {
        $b = $this->billing();
        return new Budget($this->store, $this->cfg->budgetDaily, $this->cfg->budgetMonthly, $this->calibration()->factor(), fn(int $from, int $to) => $b->adjust($from, $to));
    }
    public function billing(): Billing { return new Billing($this); }
    public function calibration(): Calibration { return new Calibration($this); }
    public function pricing(): Pricing { return new Pricing($this->cfg->prices, $this->cfg->localModels() || $this->cfg->provider === 'echo'); }
    public function notifier(): Notifier { return new Notifier($this); }
    public function email(): Email { return new Email($this); }
    public function files(): Files { return new Files($this); }
    public function library(): Library { return new Library($this); }
    public function documents(): Documents { return new Documents($this); }
    public function legifrance(): Legifrance { return new Legifrance($this); }
    public function eurlex(): Eurlex { return new Eurlex($this); }
    public function approvals(): Approvals { return new Approvals($this); }
    public function skills(): Skills { return new Skills($this); }
    public function assessor(): Assessor { return new Assessor($this); }
    public function director(): Director { return new Director($this); }
    public function relay(): Relay { return new Relay($this); }
    public function claudeCli(): ClaudeCli { return $this->claudeCliOverride ?? new ShellClaudeCli($this->cfg->claudeBin, $this->cfg->dataDir . '/conductor/logs', $this->cfg->claudeToken); }
    public function conductor(): Conductor { return new Conductor($this, $this->claudeCli()); }
    public function mind(): Mind { return new Mind($this); }
    public function graph(): Graph { return new Graph($this); }
    public function shares(): Shares { return new Shares($this); }
    public function privacy(): Privacy { return $this->privacy ??= new Privacy($this); }
    public function backup(): Backup { return new Backup($this); }
    public function updater(): Updater { return new Updater($this); }
    public function calendar(): Calendar { return new Calendar($this); }
    public function mailbox(): Mailbox { return new Mailbox($this); }
    public function contacts(): Contacts { return new Contacts($this); }
    public function triage(): Triage { return new Triage($this); }
    public function accounts(): Accounts { return new Accounts($this); }
    public function spaces(): Spaces { return new Spaces($this); }
    public function links(): Links { return new Links($this); }
    public function routines(): Routines { return new Routines($this); }
    public function board(): Board { return $this->board ??= new Board($this); }
    public function google(): Google { return new Google($this); }
    public function microsoft(string $purpose = ''): Microsoft { return new Microsoft($this, $purpose); }
    public function spotify(): Spotify { return new Spotify($this); }
    public function shell(): Shell { return new Shell($this); }
    public function scripts(): Scripts { return new Scripts($this); }
    public function apps(): Apps { return new Apps($this); }
    public function mcp(): Mcp { return new Mcp($this); }
    public function mcpOAuth(): McpOAuth { return new McpOAuth($this); }
    public function browserAvailable(): bool { return $this->cfg->browser !== 'off' && Browser::findBinary($this->cfg->chromeBin) !== null; }
    public function transcriber(): Transcriber { return new Transcriber($this); }
    public function webPush(): WebPush { return new WebPush($this); }
    public function signal(): Signal { return new Signal($this); }
}
