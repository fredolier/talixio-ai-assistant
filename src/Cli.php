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
                    fwrite(STDERR, "usage: talixio worker|chat|doctor|mcp|job ID\n");
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
        foreach (['curl', 'pdo_sqlite', 'mbstring', 'openssl'] as $ext) $line(extension_loaded($ext), "php extension $ext");
        $line(is_writable($c->dataDir), "data dir {$c->dataDir} writable");
        $line($c->webPassword !== '', 'web password set');
        $line(match ($c->provider) {
            'anthropic' => $c->anthropicKey !== '', 'openai' => $c->openaiKey !== '' || !str_contains($c->openaiBaseUrl, 'openai.com'),
            'gemini' => $c->openaiKey !== '',
            'claude-code', 'echo' => true, default => false,
        }, "provider {$c->provider} credentials");
        if ($c->embeddings !== 'off') {
            try { $app->embeddings->embed(['test'], 'query'); $line(true, "embeddings {$c->embeddings}:{$c->embeddingsModel}"); }
            catch (\Throwable $e) { $line(false, "embeddings {$c->embeddings}: {$e->getMessage()}"); }
        }
        if ($app->email()->canSend()) {
            try { (new Smtp($c->smtpHost, $c->smtpPort, $c->smtpSecurity, $c->smtpUser, $c->smtpPassword))->check(); $line(true, "SMTP {$c->smtpHost}:{$c->smtpPort} login"); }
            catch (\Throwable $e) { $line(false, "SMTP: {$e->getMessage()}"); }
        }
        if ($c->imapHost !== '') {
            try { (new Imap($c->imapHost, $c->imapPort, $c->imapUser, $c->imapPassword, $c->imapFolder, $c->imapSecurity))->fetchUnseen(0); $line(true, "IMAP {$c->imapHost}:{$c->imapPort} login"); }
            catch (\Throwable $e) { $line(false, "IMAP: {$e->getMessage()}"); }
            $line($c->emailAllowed !== [], 'email senders allowlisted: ' . (implode(', ', $c->emailAllowed) ?: 'none (set TALIXIO_EMAIL_ALLOWED)'));
        }
        if ($app->calendar()->enabled()) {
            try { $n = count($app->calendar()->events(strtotime('today'), strtotime('today') + 7 * 86400)); $line(true, "calendar: $n event(s) in the next 7 days" . ($app->calendar()->canWrite() ? ' (CalDAV: can add events)' : ' (read-only)')); }
            catch (\Throwable $e) { $line(false, "calendar: {$e->getMessage()}"); }
        }
        if ($app->mailbox()->enabled()) {
            try { $folders = (new Imap($c->mailboxImapHost, $c->mailboxImapPort, $c->mailboxUser, $c->mailboxPassword, 'INBOX', $c->mailboxImapSecurity))->folders(); $line(true, 'mailbox login (' . count($folders) . ' folders)'); }
            catch (\Throwable $e) { $line(false, "mailbox: {$e->getMessage()}"); }
        }
        if ($c->signalNumber !== '') {
            $found = is_executable($c->signalCli) || trim((string)shell_exec('command -v ' . escapeshellarg($c->signalCli) . ' 2>/dev/null')) !== '';
            $line($found, "signal-cli found ({$c->signalCli})");
            $line($c->signalAllowed !== [], 'Signal senders allowlisted');
        }
        echo '     notifications: ' . json_encode($app->notifier()->channels()) . "\n";
        echo '     budget: ' . $app->budget()->describe() . "\n";
        echo "     embeddings: {$c->embeddings} | search: {$c->search} | telegram: " . ($c->telegramToken ? 'on' : 'off') . ' | ntfy: ' . ($c->ntfyUrl ? 'on' : 'off')
            . " | heartbeat: " . ($c->heartbeatMinutes ?: 'off') . " | timezone: {$c->timezone}\n";
        try {
            foreach ($c->tiers as $tier) {
                $t = microtime(true);
                $r = $app->provider($tier)->complete('Reply with exactly: pong', '', [['role' => 'user', 'content' => 'ping']], $app->tools->only('none'), fn() => null);
                $line(stripos($r, 'pong') !== false, sprintf('%s tier (%s) answered "%s" in %.1fs', $tier->name, $tier->label(), mb_substr(trim($r), 0, 30), microtime(true) - $t));
            }
        } catch (\Throwable $e) {
            $line(false, 'model call failed: ' . $e->getMessage());
        }
        return $ok ? 0 : 1;
    }
}
