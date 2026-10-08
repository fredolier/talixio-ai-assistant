<?php
declare(strict_types=1);
namespace Talixio;

final class Cli
{
    public static function main(array $argv): int
    {
        $cmd = $argv[1] ?? 'help';
        try {
            $app = App::fromEnv();
            switch ($cmd) {
                case 'worker':
                    (new Worker($app))->run();
                case 'mcp':
                    (new McpServer($app->tools))->serve();
                    return 0;
                case 'chat':
                    echo "{$app->cfg->name} (provider {$app->cfg->provider}). Same thread as the web app. Ctrl-D to quit.\n> ";
                    $agent = $app->agent();
                    while (($line = fgets(STDIN)) !== false) {
                        if (trim($line) !== '') echo $agent->chat('cli', trim($line)), "\n";
                        echo '> ';
                    }
                    return 0;
                case 'doctor':
                    return self::doctor($app);
                default:
                    fwrite(STDERR, "usage: talixio worker|chat|doctor|mcp\n");
                    return 2;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");
            return 1;
        }
    }

    /** Checks the configuration and makes one real model call. */
    private static function doctor(App $app): int
    {
        $c = $app->cfg;
        $ok = true;
        $line = function (bool $good, string $msg) use (&$ok) { echo ($good ? ' ok  ' : ' !!  ') . $msg . "\n"; $ok = $ok && $good; };
        echo "Talixio doctor\n";
        $line(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . ' (need 8.2+)');
        foreach (['curl', 'pdo_sqlite', 'mbstring'] as $ext) $line(extension_loaded($ext), "php extension $ext");
        $line(is_writable($c->dataDir), "data dir {$c->dataDir} writable");
        $line($c->webPassword !== '', 'web password set');
        $line(match ($c->provider) {
            'anthropic' => $c->anthropicKey !== '', 'openai' => $c->openaiKey !== '' || !str_contains($c->openaiBaseUrl, 'openai.com'),
            'claude-code', 'echo' => true, default => false,
        }, "provider {$c->provider} credentials");
        echo "     search: {$c->search} | telegram: " . ($c->telegramToken ? 'on' : 'off') . ' | ntfy: ' . ($c->ntfyUrl ? 'on' : 'off')
            . " | heartbeat: " . ($c->heartbeatMinutes ?: 'off') . " | timezone: {$c->timezone}\n";
        try {
            $t = microtime(true);
            $r = $app->provider()->complete('Reply with exactly: pong', [['role' => 'user', 'content' => 'ping']], new Tools($c, $app->store, $app->ws), fn() => null);
            $line(stripos($r, 'pong') !== false, sprintf('model call answered "%s" in %.1fs', mb_substr(trim($r), 0, 40), microtime(true) - $t));
        } catch (\Throwable $e) {
            $line(false, 'model call failed: ' . $e->getMessage());
        }
        return $ok ? 0 : 1;
    }
}
