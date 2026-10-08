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
    /** Set in tests to replace the configured model. */
    public ?Provider $providerOverride = null;

    public function __construct(public Config $cfg)
    {
        $this->store = new Store($cfg->dbPath());
        if ($cfg->provider === 'gemini') GeminiModels::apply($cfg, $this->store);
        $this->ws = new Workspace($cfg->workspaceDir());
        $this->embeddings = new Embeddings($cfg);
        $this->tools = new Tools($this);
        Content::$imageLoader = fn(int $id) => $this->files()->imagePart($id);
    }

    public static function fromEnv(): self { return new self(Config::fromEnv()); }

    public function provider(Tier $tier): Provider
    {
        if ($this->providerOverride) return $this->providerOverride;
        $c = $this->cfg;
        return match ($c->provider) {
            'anthropic' => new AnthropicProvider($c->anthropicKey, $tier, $c->search === 'builtin', $c->anthropicBaseUrl),
            'openai', 'gemini' => new OpenAIProvider($c->openaiKey, $tier, $c->openaiBaseUrl),
            'claude-code' => new ClaudeCodeProvider($c->claudeBin, $tier, $c->search === 'builtin', dirname(__DIR__) . '/bin/talixio'),
            'echo' => new EchoProvider(),
            default => throw new \InvalidArgumentException("unknown TALIXIO_PROVIDER {$c->provider}"),
        };
    }

    public function agent(): Agent { return new Agent($this); }
    public function jobs(): Jobs { return new Jobs($this); }
    public function recall(): Recall { return new Recall($this->store, $this->embeddings); }
    public function budget(): Budget { return new Budget($this->store, $this->cfg->budgetDaily, $this->cfg->budgetMonthly); }
    public function pricing(): Pricing { return new Pricing($this->cfg->prices, $this->cfg->localModels() || $this->cfg->provider === 'echo'); }
    public function notifier(): Notifier { return new Notifier($this); }
    public function email(): Email { return new Email($this); }
    public function files(): Files { return new Files($this); }
    public function approvals(): Approvals { return new Approvals($this); }
    public function skills(): Skills { return new Skills($this); }
    public function backup(): Backup { return new Backup($this); }
    public function calendar(): Calendar { return new Calendar($this); }
    public function mailbox(): Mailbox { return new Mailbox($this); }
    public function browserAvailable(): bool { return $this->cfg->browser !== 'off' && Browser::findBinary($this->cfg->chromeBin) !== null; }
    public function transcriber(): Transcriber { return new Transcriber($this); }
    public function webPush(): WebPush { return new WebPush($this); }
    public function signal(): Signal { return new Signal($this); }
}
