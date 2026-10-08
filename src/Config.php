<?php
declare(strict_types=1);
namespace Talixio;

/** All settings come from environment variables (see config/talixio.env.example). */
final class Config
{
    public string $provider = 'anthropic';      // anthropic | openai | claude-code
    public string $model = '';
    public string $cheapModel = '';             // used for heartbeat checks; defaults to $model
    public string $anthropicKey = '';
    public string $anthropicBaseUrl = 'https://api.anthropic.com';
    public string $openaiKey = '';
    public string $openaiBaseUrl = 'https://api.openai.com/v1';
    public string $claudeBin = 'claude';
    public string $dataDir = 'data';
    public string $timezone = 'UTC';
    public string $name = 'Talixio';
    public int $historyMessages = 40;
    public string $webPassword = '';
    public string $telegramToken = '';
    /** @var int[] */ public array $telegramAllowed = [];
    public string $ntfyUrl = '';
    public string $ntfyToken = '';
    public int $heartbeatMinutes = 0;
    public string $search = 'auto';             // auto | anthropic | brave | tavily | searxng | off
    public string $braveKey = '';
    public string $tavilyKey = '';
    public string $searxngUrl = '';

    public static function fromEnv(): self
    {
        $e = static fn(string $k, string $d = ''): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
        $c = new self();
        $c->provider = strtolower($e('TALIXIO_PROVIDER', 'anthropic'));
        $c->model = $e('TALIXIO_MODEL', match ($c->provider) {
            'openai' => 'gpt-4.1', 'claude-code', 'echo' => '', default => 'claude-sonnet-5-5',
        });
        $c->cheapModel = $e('TALIXIO_CHEAP_MODEL', $c->model);
        $c->anthropicKey = $e('ANTHROPIC_API_KEY');
        $c->anthropicBaseUrl = rtrim($e('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/');
        $c->openaiKey = $e('OPENAI_API_KEY');
        $c->openaiBaseUrl = rtrim($e('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $c->claudeBin = $e('TALIXIO_CLAUDE_BIN', 'claude');
        $c->dataDir = rtrim($e('TALIXIO_DATA_DIR', 'data'), '/');
        if (!str_starts_with($c->dataDir, '/')) $c->dataDir = getcwd() . '/' . $c->dataDir; // child processes run elsewhere
        $c->timezone = $e('TALIXIO_TIMEZONE', 'UTC');
        $c->name = $e('TALIXIO_NAME', 'Talixio');
        $c->historyMessages = (int)$e('TALIXIO_HISTORY_MESSAGES', '40');
        $c->webPassword = $e('TALIXIO_WEB_PASSWORD');
        $c->telegramToken = $e('TALIXIO_TELEGRAM_TOKEN');
        $c->telegramAllowed = array_map('intval', array_filter(array_map('trim', explode(',', $e('TALIXIO_TELEGRAM_ALLOWED_IDS'))), 'strlen'));
        $c->ntfyUrl = $e('TALIXIO_NTFY_URL');
        $c->ntfyToken = $e('TALIXIO_NTFY_TOKEN');
        $c->heartbeatMinutes = (int)$e('TALIXIO_HEARTBEAT_MINUTES', '0');
        $c->search = strtolower($e('TALIXIO_SEARCH', 'auto'));
        $c->braveKey = $e('BRAVE_API_KEY');
        $c->tavilyKey = $e('TAVILY_API_KEY');
        $c->searxngUrl = rtrim($e('SEARXNG_URL'), '/');
        if ($c->search === 'auto') {
            $c->search = match (true) {
                $c->braveKey !== '' => 'brave',
                $c->tavilyKey !== '' => 'tavily',
                $c->searxngUrl !== '' => 'searxng',
                in_array($c->provider, ['anthropic', 'claude-code'], true) => 'builtin',
                default => 'off',
            };
        }
        date_default_timezone_set($c->timezone);
        return $c;
    }

    public function dbPath(): string { return $this->dataDir . '/talixio.db'; }
    public function workspaceDir(): string { return $this->dataDir . '/workspace'; }
}
