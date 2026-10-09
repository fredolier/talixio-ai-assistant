<?php
declare(strict_types=1);
namespace Talixio;

/** Configuration checks with real logins and one model call per tier (`talixio doctor` and the Settings page). */
final class Doctor
{
    private array $out = [];

    public function __construct(private App $app) {}

    private function line(bool $good, string $msg): void { $this->out[] = ['status' => $good ? 'ok' : 'fail', 'text' => $msg]; }
    private function info(string $msg): void { $this->out[] = ['status' => 'info', 'text' => $msg]; }

    private function attempt(string $label, callable $fn): void
    {
        try { $this->line(true, $label . (($r = $fn()) ? ": $r" : '')); }
        catch (\Throwable $e) { $this->line(false, "$label: {$e->getMessage()}"); }
    }

    /** @return array<array{status:string,text:string}> */
    public function run(bool $modelCalls = true): array
    {
        $app = $this->app; $c = $app->cfg;
        $this->line(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . ' (8.2+ needed)');
        foreach (['curl', 'pdo_sqlite', 'mbstring', 'openssl'] as $ext) $this->line(extension_loaded($ext), "PHP extension $ext");
        $this->line(is_writable($c->dataDir), "data folder {$c->dataDir} writable");
        $this->line((new Auth($app))->hasPassword(), 'app password set');
        $this->line(match ($c->provider) {
            'anthropic' => $c->anthropicKey !== '', 'openai' => $c->openaiKey !== '' || !str_contains($c->openaiBaseUrl, 'openai.com'),
            'gemini' => $c->openaiKey !== '', 'claude-code', 'echo' => true, default => false,
        }, "{$c->provider} credentials");
        if ($c->embeddings !== 'off') {
            $this->attempt("search by meaning ({$c->embeddings}: {$c->embeddingsModel})", function () use ($app, $c) {
                if ($c->embeddings === 'ollama') {
                    [$code, $body] = Http::request('GET', $c->ollamaUrl . '/api/tags', [], null, 10);
                    $names = array_column(json_decode($body, true)['models'] ?? [], 'name');
                    if (!in_array($c->embeddingsModel, $names, true) && !in_array($c->embeddingsModel . ':latest', $names, true)) {
                        throw new \RuntimeException("model not installed in Ollama: run \"ollama pull {$c->embeddingsModel}\"");
                    }
                }
                $t = microtime(true);
                $v = $app->embeddings->embed(['Rendez-vous chez le dentiste mardi à 14 h'], 'query')[0];
                [$done, $total] = $app->recall()->coverage();
                return sprintf('%d dimensions, %.0f ms; %d of %d memory items indexed', strlen($v) / 4, (microtime(true) - $t) * 1000, $done, $total);
            });
        }
        if ($c->transcribe !== 'off') $this->info("transcription: {$c->transcribe}");
        if ($app->email()->canSend()) $this->attempt("SMTP {$c->smtpHost}:{$c->smtpPort}", fn() => (new Smtp($c->smtpHost, $c->smtpPort, $c->smtpSecurity, $c->smtpUser, $c->smtpPassword))->check() ?? 'login OK');
        if ($c->imapHost !== '') {
            $this->attempt("IMAP {$c->imapHost}:{$c->imapPort}", fn() => (new Imap($c->imapHost, $c->imapPort, $c->imapUser, $c->imapPassword, $c->imapFolder, $c->imapSecurity))->fetchUnseen(0) === [] ? 'login OK' : '');
            $this->line($c->emailAllowed !== [], 'allowed senders: ' . (implode(', ', $c->emailAllowed) ?: 'none'));
        }
        if ($app->calendar()->enabled()) $this->attempt('calendar', fn() => count($app->calendar()->events(strtotime('today'), strtotime('today') + 7 * 86400))
            . ' event(s) in the next 7 days' . ($app->calendar()->canWrite() ? ' (CalDAV: can add events)' : ' (read-only)'));
        if ($app->mailbox()->enabled()) $this->attempt('your mailbox', fn() => count((new Imap($c->mailboxImapHost, $c->mailboxImapPort, $c->mailboxUser, $c->mailboxPassword, 'INBOX', $c->mailboxImapSecurity))->folders()) . ' folders');
        if ($c->browser !== 'off') {
            $bin = Browser::findBinary($c->chromeBin);
            $bin ? $this->line(true, "browser: $bin") : $this->info('browser: not installed (optional: install.sh --with-browser)');
        }
        if ($c->signalNumber !== '') {
            $found = is_executable($c->signalCli) || trim((string)shell_exec('command -v ' . escapeshellarg($c->signalCli) . ' 2>/dev/null')) !== '';
            $this->line($found, "signal-cli ({$c->signalCli})");
            $this->line($c->signalAllowed !== [], 'Signal numbers allowed');
        }
        $this->info('notifications: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($n = $app->notifier()->channels()), $n)));
        $this->info('budget: ' . $app->budget()->describe());
        $this->info("web search: {$c->search} · embeddings: {$c->embeddings} · time zone: {$c->timezone}");
        if ($modelCalls) {
            foreach ($c->tiers as $tier) {
                $t = microtime(true);
                $this->attempt("{$tier->name} tier ({$tier->label()})", function () use ($app, $tier, $t) {
                    $r = $app->provider($tier)->complete('Reply with exactly: pong', '', [['role' => 'user', 'content' => 'ping']], $app->tools->only('none'), fn() => null);
                    if (stripos($r, 'pong') === false) throw new \RuntimeException('unexpected answer: ' . mb_substr(trim($r), 0, 60));
                    return sprintf('answers in %.1fs', microtime(true) - $t);
                });
            }
        }
        return $this->out;
    }
}
