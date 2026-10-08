<?php
declare(strict_types=1);
namespace Talixio;

/** Wires the pieces together. */
final class App
{
    public Store $store;
    public Workspace $ws;
    public Tools $tools;
    /** Set in tests to replace the configured model. */
    public ?Provider $providerOverride = null;

    public function __construct(public Config $cfg)
    {
        $this->store = new Store($cfg->dbPath());
        $this->ws = new Workspace($cfg->workspaceDir());
        $this->tools = new Tools($cfg, $this->store, $this->ws);
    }

    public static function fromEnv(): self { return new self(Config::fromEnv()); }

    public function provider(bool $cheap = false): Provider
    {
        if ($this->providerOverride) return $this->providerOverride;
        $c = $this->cfg;
        $model = $cheap ? $c->cheapModel : $c->model;
        return match ($c->provider) {
            'anthropic' => new AnthropicProvider($c->anthropicKey, $model, $c->search === 'builtin', $c->anthropicBaseUrl),
            'openai' => new OpenAIProvider($c->openaiKey, $model, $c->openaiBaseUrl),
            'claude-code' => new ClaudeCodeProvider($c->claudeBin, $model, $c->search === 'builtin', dirname(__DIR__) . '/bin/talixio'),
            'echo' => new EchoProvider(),
            default => throw new \InvalidArgumentException("unknown TALIXIO_PROVIDER {$c->provider}"),
        };
    }

    public function agent(?Provider $provider = null): Agent
    {
        return new Agent($this, $provider ?? $this->provider());
    }

    public function notifier(): Notifier { return new Notifier($this->cfg); }
}
