<?php
declare(strict_types=1);
namespace Talixio;

final class Cli
{
    public static function main(array $argv): int
    {
        $cmd = $argv[1] ?? 'help';
        if (in_array($cmd, ['version', '--version', '-v'], true)) { echo 'Talixio ' . Version::get()['label'] . "\n"; return 0; }
        try {
            if ($cmd === 'transfer-pull') { // talixio transfer-pull <old address> <code> [--final] [--url <new address>]; services stopped
                $args = array_slice($argv, 2);
                $final = in_array('--final', $args, true);
                $i = array_search('--url', $args, true);
                $url = $i !== false ? (string)($args[$i + 1] ?? '') : '';
                $pos = array_values(array_filter($args, fn($a, $k) => $a[0] !== '-' && ($i === false || $k !== $i + 1), ARRAY_FILTER_USE_BOTH));
                if (count($pos) < 2) { fwrite(STDERR, "usage: talixio transfer-pull <old address> <transfer code> [--final] [--url <this assistant's new address>]\n"); return 2; }
                echo Transfer::pull(Config::fromEnv()->dataDir, $pos[0], $pos[1], $final, $url, fn($m) => print("$m\n")), "\n";
                return 0;
            }
            if ($cmd === 'claude-login') { ClaudeLogin::run(Config::fromEnv()); return 0; } // started by the web app (Sign in with Claude)
            $app = App::fromEnv();
            switch ($cmd) {
                case 'worker':
                    (new Worker($app))->run();
                case 'job':
                    $app->jobs()->runJob((int)($argv[2] ?? 0));
                    return 0;
                case 'connector-install': // started by Settings → Connectors: dependencies, then the connector is added
                    (new ConnectorPackage($app))->finish();
                    return 0;
                case 'embeddings-pull': // started by Settings → Search by meaning: download a model into this server's Ollama
                    (new Meaning($app))->pull((string)($argv[2] ?? ''));
                    return 0;
                case 'index': // started by the worker: read and index library files
                    $n = $app->files()->processPending(600);
                    if ($n) Log::info("library: indexed $n file(s)");
                    return 0;
                case 'mcp': // Claude Code runs this for each turn: tools act in the turn's space
                    $app->space = max(0, (int)getenv('TALIXIO_SPACE'));
                    App::$inWorker = getenv('TALIXIO_IN_WORKER') === '1';
                    $app->jobId = (int)getenv('TALIXIO_JOB') ?: null;
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
                case 'boot': // container start (multi-user server): first password, and resets asked from the admin page
                    $dir = $app->cfg->dataDir; $over = Settings::load($dir);
                    if (($h = (string)getenv('TALIXIO_INIT_PASSWORD_HASH')) !== '' && ($over['TALIXIO_WEB_PASSWORD_HASH'] ?? '') === '' && !isset($over['TALIXIO_INIT_DONE'])) {
                        $over['TALIXIO_WEB_PASSWORD_HASH'] = $h; $over['TALIXIO_INIT_DONE'] = '1';
                    }
                    $r = (string)getenv('TALIXIO_RESET'); // "<nonce>:password:<hash>" or "<nonce>:totp", applied once
                    if ($r !== '' && ($over['TALIXIO_RESET_DONE'] ?? '') !== strtok($r, ':')) {
                        [$nonce, $what, $hash] = array_pad(explode(':', $r, 3), 3, '');
                        if ($what === 'password' && $hash !== '') { $over['TALIXIO_WEB_PASSWORD_HASH'] = $hash; echo "password reset\n"; }
                        if ($what === 'totp') { unset($over['TALIXIO_TOTP_SECRET']); echo "two-step sign-in turned off\n"; }
                        $over['TALIXIO_RESET_DONE'] = $nonce;
                    }
                    Settings::write($dir, $over);
                    return 0;
                case 'backup':
                    echo $app->backup()->run(), "\n";
                    return 0;
                case 'doctor':
                    return self::doctor($app);
                case 'password': // reset the web app password (forgotten), read from stdin or generated
                    $pw = stream_isatty(STDIN) ? '' : trim((string)fgets(STDIN));
                    if ($pw === '') $pw = substr(strtr(base64_encode(random_bytes(18)), '+/=', 'xyz'), 0, 20);
                    if (mb_strlen($pw) < 12) { fwrite(STDERR, "at least 12 characters\n"); return 1; }
                    Settings::setPassword($app->cfg->dataDir, $pw);
                    echo "New app password: $pw\nEvery device has to sign in again.\n";
                    return 0;
                case 'totp': // "totp off": turn two-step sign-in off (lost phone)
                    if (($argv[2] ?? '') !== 'off') { echo 'Two-step sign-in is ' . ((new Auth($app))->totpEnabled() ? 'on' : 'off') . ". Turn it off with: talixio totp off\n"; return 0; }
                    Settings::setTotp($app->cfg->dataDir, '');
                    echo "Two-step sign-in is off. Turn it on again in Settings.\n";
                    return 0;
                case 'signal': // signal-cli for the assistant's number (register, verify, link...); in the container, where Settings hold the number
                    $c = $app->cfg;
                    if ($c->signalNumber === '') { fwrite(STDERR, "Set the assistant's number in Settings → Signal first.\n"); return 1; }
                    @mkdir($c->dataDir . '/signal', 0700, true);
                    $p = proc_open(array_merge([$c->signalCli, '--config', $c->dataDir . '/signal', '-a', $c->signalNumber], array_slice($argv, 2)), [STDIN, STDOUT, STDERR], $pipes);
                    return is_resource($p) ? proc_close($p) : 1;
                default:
                    fwrite(STDERR, "usage: talixio version|worker|chat|doctor|backup|password|totp [off]|signal ARGS|transfer-pull URL CODE [--final] [--url URL]|mcp|index|job ID\n");
                    return 2;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");
            return 1;
        }
    }

    private static function doctor(App $app): int
    {
        echo "Talixio doctor, version " . Version::get()['label'] . "\n";
        $ok = true;
        foreach ((new Doctor($app))->run() as $r) {
            echo match ($r['status']) { 'ok' => ' ok  ', 'fail' => ' !!  ', default => '     ' }, $r['text'], "\n";
            if ($r['status'] === 'fail') $ok = false;
        }
        return $ok ? 0 : 1;
    }
}
