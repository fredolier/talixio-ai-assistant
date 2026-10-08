<?php
declare(strict_types=1);
namespace Talixio;

final class Config
{
    public function __construct(
        public string $provider = 'anthropic',   // anthropic | openai
        public string $model = '',
        public string $anthropicKey = '',
        public string $openaiKey = '',
        public string $openaiBaseUrl = 'https://api.openai.com/v1', // or Ollama/vLLM
        public string $dbPath = 'data/talixio.db',
        public string $name = 'Talixio',
        public string $persona = 'You are a concise, helpful personal assistant.',
        public int $historyTurns = 20,
        public string $telegramToken = '',
        /** @var int[] */ public array $telegramAllowed = [],
        /** @var array<array{0:string,1:int,2:string}> UTC "HH:MM|chat_id|prompt" jobs */ public array $dailyJobs = [],
    ) {}

    public static function fromEnv(): self
    {
        $e = static fn(string $k, string $d = ''): string => (string)(getenv($k) !== false ? getenv($k) : $d);
        $provider = strtolower($e('TALIXIO_PROVIDER', 'anthropic'));
        $jobs = [];
        foreach (array_filter(array_map('trim', explode(';;', $e('TALIXIO_DAILY_JOBS')))) as $raw) {
            [$when, $chat, $prompt] = array_pad(explode('|', $raw, 3), 3, '');
            $jobs[] = [trim($when), (int)$chat, trim($prompt)];
        }
        return new self(
            provider: $provider,
            model: $e('TALIXIO_MODEL', $provider === 'openai' ? 'gpt-4.1' : 'claude-sonnet-5-5'),
            anthropicKey: $e('ANTHROPIC_API_KEY'),
            openaiKey: $e('OPENAI_API_KEY'),
            openaiBaseUrl: rtrim($e('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            dbPath: $e('TALIXIO_DB_PATH', 'data/talixio.db'),
            name: $e('TALIXIO_NAME', 'Talixio'),
            persona: $e('TALIXIO_PERSONA', 'You are a concise, helpful personal assistant.'),
            historyTurns: (int)$e('TALIXIO_HISTORY_TURNS', '20'),
            telegramToken: $e('TALIXIO_TELEGRAM_TOKEN'),
            telegramAllowed: array_map('intval', array_filter(array_map('trim', explode(',', $e('TALIXIO_TELEGRAM_ALLOWED_IDS'))), 'strlen')),
            dailyJobs: $jobs,
        );
    }
}
