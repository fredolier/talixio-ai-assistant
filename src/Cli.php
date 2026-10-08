<?php
declare(strict_types=1);
namespace Talixio;

final class Cli
{
    public static function main(array $argv): int
    {
        $cfg = Config::fromEnv();
        $cmd = $argv[1] ?? 'serve';
        if (!in_array($cmd, ['serve', 'chat'], true)) { fwrite(STDERR, "usage: talixio [serve|chat]\n"); return 2; }
        $provider = match ($cfg->provider) {
            'anthropic' => new AnthropicProvider($cfg->anthropicKey, $cfg->model),
            'openai' => new OpenAIProvider($cfg->openaiKey, $cfg->model, $cfg->openaiBaseUrl),
            default => throw new \InvalidArgumentException("unknown provider {$cfg->provider}"),
        };
        $agent = new Agent($cfg, $provider, new Memory($cfg->dbPath));
        if ($cmd === 'chat') {
            echo "{$cfg->name} CLI. Ctrl-D to quit.\n";
            while (($line = fgets(STDIN)) !== false) {
                if (trim($line) !== '') echo $agent->reply('cli', trim($line)), "\n";
            }
            return 0;
        }
        if ($cfg->telegramToken === '') { fwrite(STDERR, "Set TALIXIO_TELEGRAM_TOKEN in /etc/talixio/talixio.env (or use `talixio chat`).\n"); return 1; }
        (new Telegram($cfg, $agent))->run();
    }
}
