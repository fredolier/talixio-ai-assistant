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
                case 'job':
                    $app->jobs()->runJob((int)($argv[2] ?? 0));
                    return 0;
                case 'index': // started by the worker: read and index library files
                    $n = $app->files()->processPending(600);
                    if ($n) Log::info("library: indexed $n file(s)");
                    return 0;
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
                case 'backup':
                    echo $app->backup()->run(), "\n";
                    return 0;
                case 'doctor':
                    return self::doctor($app);
                default:
                    fwrite(STDERR, "usage: talixio worker|chat|doctor|backup|mcp|index|job ID\n");
                    return 2;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");
            return 1;
        }
    }

    private static function doctor(App $app): int
    {
        echo "Talixio doctor\n";
        $ok = true;
        foreach ((new Doctor($app))->run() as $r) {
            echo match ($r['status']) { 'ok' => ' ok  ', 'fail' => ' !!  ', default => '     ' }, $r['text'], "\n";
            if ($r['status'] === 'fail') $ok = false;
        }
        return $ok ? 0 : 1;
    }
}
