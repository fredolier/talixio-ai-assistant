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
        $eu = $c->provider === 'sovereign' ? Sovereign::SERVICES[$c->sovereign] ?? null : null;
        $this->line($c->modelProblem() === null, $eu ? "European provider key ({$eu['name']})" : "{$c->provider} credentials");
        if ($eu) { // the European stamp, or what still leaves Europe
            $out = Sovereign::outside($app);
            if ($out) $this->line(false, 'Europe only: still outside Europe: ' . implode(', ', array_column($out, 'name')));
            else $this->out[] = ['status' => 'eu', 'text' => "Everything stays in Europe: {$eu['name']} ({$eu['country']}), under the GDPR" . ($c->sovereignOnly ? ', locked' : ''),
                    'provider' => "{$eu['name']} ({$eu['country']})", 'locked' => $c->sovereignOnly];
        }
        $ec = $app->embedCfg(); $vc = $app->voiceCfg();
        if ($ec->embeddings !== 'off') {
            $this->attempt("search by meaning ({$ec->embeddings}: {$ec->embeddingsModel})", function () use ($app, $ec) {
                if ($ec->embeddings === 'ollama') {
                    [$code, $body] = Http::request('GET', $ec->ollamaUrl . '/api/tags', [], null, 10);
                    $names = array_column(json_decode($body, true)['models'] ?? [], 'name');
                    if (!in_array($ec->embeddingsModel, $names, true) && !in_array($ec->embeddingsModel . ':latest', $names, true)) {
                        throw new \RuntimeException("model not installed in Ollama: run \"ollama pull {$ec->embeddingsModel}\"");
                    }
                }
                $t = microtime(true);
                $v = $app->embeddings->embed(['Rendez-vous chez le dentiste mardi à 14 h'], 'query')[0];
                [$done, $total] = $app->recall()->coverage();
                return sprintf('%d dimensions, %.0f ms; %d of %d memory items indexed', strlen($v) / 4, (microtime(true) - $t) * 1000, $done, $total);
            });
        }
        if ($vc->transcribe === 'local') {
            [$bin, $model] = Transcriber::whisper($vc);
            $this->line($bin !== '' && is_file($model), 'transcription: local, ' . ($bin === '' ? 'whisper.cpp not installed (' . Transcriber::whisperHint() . ')' : (is_file($model) ? basename($model) : "model missing: $model (" . Transcriber::whisperHint() . ')')));
        } elseif ($vc->transcribe !== 'off') $this->info("transcription: {$vc->transcribe}");
        if ($app->email()->canSend()) $this->attempt("SMTP {$c->smtpHost}:{$c->smtpPort}", fn() => $app->email()->smtp()->check() ?? 'login OK');
        if ($c->imapHost !== '') {
            $this->attempt("IMAP {$c->imapHost}:{$c->imapPort}", fn() => $app->email()->imap()->fetchUnseen(0) === [] ? 'login OK' : '');
            $this->line($c->emailAllowed !== [], 'allowed senders: ' . (implode(', ', $c->emailAllowed) ?: 'none'));
        }
        if ($app->calendar()->enabled()) $this->attempt('calendar', fn() => count($app->calendar()->events(strtotime('today'), strtotime('today') + 7 * 86400))
            . ' event(s) in the next 7 days' . ($app->calendar()->canWrite() ? ' (CalDAV: can add events)' : ' (read-only)'));
        if ($app->mailbox()->enabled()) $this->attempt('your mailbox', fn() => count($app->mailbox()->imap()->folders()) . ' folders');
        if ($c->browser !== 'off') {
            $bin = Browser::findBinary($c->chromeBin);
            $bin ? $this->line(true, "browser: $bin") : $this->info('browser: not installed (optional: install.sh --with-browser)');
        }
        $this->info('Office pages: ' . (Files::officeBinary() ? 'LibreOffice (' . Files::officeBinary() . ')' : 'simple view only (install.sh --with-office for faithful pages)'));
        if ($c->shell !== 'off') $this->attempt("sandboxed shell ({$c->shell}" . ($c->shellNetwork ? ', internet on' : '') . ')', fn() => $app->shell()->check());
        foreach ($app->mcp()->cache() as $name => $info) {
            if (!isset($app->mcp()->servers()[$name])) continue;
            $info['error'] ? $this->line(false, "connector $name: {$info['error']}") : $this->line(true, "connector $name: " . count($info['tools']) . ' tool(s)');
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
