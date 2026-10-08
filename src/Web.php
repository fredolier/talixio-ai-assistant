<?php
declare(strict_types=1);
namespace Talixio;

/** JSON API for the web app. Single user, password login, session cookie. */
final class Web
{
    public function __construct(private App $app) {}

    public function handle(string $method, string $path): void
    {
        if ($path === '/' || !str_starts_with($path, '/api/')) {
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'");
            readfile(dirname(__DIR__) . '/web/public/app.html');
            return;
        }
        ob_start(); // anything printed by accident is dropped, not mixed into the JSON
        $this->startSession();
        try {
            if ($method === 'POST' && ($_SERVER['HTTP_X_TALIXIO'] ?? '') !== '1') $this->fail(403, 'missing request header');
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            if ($path === '/api/login' && $method === 'POST') { $this->login((string)($body['password'] ?? '')); return; }
            if (empty($_SESSION['ok'])) $this->fail(401, 'Please sign in.');
            session_write_close(); // don't hold the session lock during long model calls
            $this->json($this->route($method, $path, $body));
        } catch (\Throwable $e) {
            Log::error("web: {$e->getMessage()}");
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function route(string $m, string $path, array $b): array
    {
        $s = $this->app->store;
        return match (true) {
            $path === '/api/logout' => $this->logout(),
            $path === '/api/status' => $this->status(),
            $path === '/api/messages' => ['messages' => $s->messagesAfter((int)($_GET['after'] ?? 0))],
            $path === '/api/chat' && $m === 'POST' => $this->chat((string)($b['text'] ?? '')),
            $path === '/api/tasks' => ['tasks' => array_map(fn($t) => $t + ['next_text' => $t['next_run'] ? date('D j M, H:i', (int)$t['next_run']) : null], $s->tasks())],
            (bool)preg_match('#^/api/tasks/(\d+)/(toggle|delete)$#', $path, $mm) && $m === 'POST' => $this->taskAction((int)$mm[1], $mm[2]),
            $path === '/api/memory' && $m === 'GET' => ['files' => $this->app->ws->all()],
            $path === '/api/memory' && $m === 'POST' => (function () use ($b) { $this->app->ws->write((string)$b['file'], (string)$b['content']); return ['ok' => true]; })(),
            $path === '/api/activity' => $this->activity(),
            $path === '/api/jobs' => ['jobs' => $s->jobs(50)],
            $path === '/api/channels' => $this->channels(),
            $path === '/api/push/key' => ['key' => $this->app->webPush()->publicKey()],
            $path === '/api/push/subscribe' && $m === 'POST' => (function () use ($b) {
                $this->app->webPush()->subscribe((string)($b['endpoint'] ?? ''), (string)($b['keys']['p256dh'] ?? ''), (string)($b['keys']['auth'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                return ['ok' => true];
            })(),
            $path === '/api/push/unsubscribe' && $m === 'POST' => (function () use ($b) { $this->app->webPush()->unsubscribe((string)($b['endpoint'] ?? '')); return ['ok' => true]; })(),
            $path === '/api/notify/test' && $m === 'POST' => ['results' => $this->app->notifier()->notify($this->app->cfg->name, 'Test notification: this channel works.')],
            (bool)preg_match('#^/api/jobs/(\d+)/cancel$#', $path, $mm) && $m === 'POST' => ['ok' => $this->app->jobs()->cancel((int)$mm[1])],
            $path === '/api/search' => ['results' => array_map(fn($h) => array_intersect_key($h, array_flip(['kind', 'ref', 'ts', 'label', 'text', 'keyword', 'sim'])),
                $this->app->recall()->search((string)($_GET['q'] ?? ''), 20))],
            default => $this->fail(404, 'not found'),
        };
    }

    private function status(): array
    {
        $c = $this->app->cfg;
        $seen = (int)$this->app->store->get('worker_seen', '0');
        return ['name' => $c->name, 'provider' => $c->provider, 'model' => $c->tier('standard')->label(), 'timezone' => $c->timezone,
            'tiers' => array_map(fn(Tier $t) => $t->label(), $c->tiers), 'budget' => $this->app->budget()->status(),
            'embeddings' => $c->embeddings, 'max_parallel_jobs' => $c->maxParallelJobs, 'job_max_usd' => $c->jobMaxUsd,
            'worker_online' => time() - $seen < 90, 'search' => $c->search,
            'channels' => ['web' => true, 'telegram' => $c->telegramToken !== '', 'email' => $this->app->email()->canReceive(),
                'signal' => $this->app->signal()->enabled(), 'ntfy' => $c->ntfyUrl !== ''],
            'heartbeat_minutes' => $c->heartbeatMinutes];
    }

    private function channels(): array
    {
        $c = $this->app->cfg; $st = $this->app->store;
        $err = fn(string $ch) => $st->get("channel_error_$ch") ?: null;
        $notify = $this->app->notifier()->channels();
        $mask = fn(string $v) => strlen($v) > 6 ? substr($v, 0, 3) . str_repeat('•', strlen($v) - 5) . substr($v, -2) : $v; // phone numbers
        return ['notify_on' => $c->notify, 'channels' => [
            ['id' => 'webpush', 'name' => 'Push notifications (this app)', 'state' => $notify['webpush'], 'error' => $err('webpush'),
             'detail' => $this->app->webPush()->count() . ' device(s) subscribed', 'chat' => false],
            ['id' => 'email', 'name' => 'Email', 'state' => $this->app->email()->canSend() ? ($this->app->email()->canReceive() ? 'on' : 'notifications only') : 'not configured',
             'error' => $err('email'), 'chat' => $this->app->email()->canReceive(),
             'detail' => $c->smtpHost ? "From {$c->emailFrom}" . ($c->emailTo ? " · notifications to {$c->emailTo}" : '') . ($c->imapHost ? ' · answers ' . implode(', ', $c->emailAllowed) : '') : 'Set TALIXIO_SMTP_* and TALIXIO_IMAP_* in the config'],
            ['id' => 'signal', 'name' => 'Signal', 'state' => $notify['signal'], 'error' => $err('signal'), 'chat' => $this->app->signal()->enabled(),
             'detail' => $c->signalNumber ? "Number {$c->signalNumber} · answers " . implode(', ', array_map($mask, $c->signalAllowed)) : 'Run install.sh --with-signal, then register a number'],
            ['id' => 'telegram', 'name' => 'Telegram', 'state' => $notify['telegram'], 'error' => $err('telegram'), 'chat' => $c->telegramToken !== '',
             'detail' => $c->telegramToken ? count($c->telegramAllowed) . ' allowed chat(s)' : 'Set TALIXIO_TELEGRAM_TOKEN'],
            ['id' => 'ntfy', 'name' => 'ntfy', 'state' => $notify['ntfy'], 'error' => $err('ntfy'), 'chat' => false,
             'detail' => $c->ntfyUrl ? 'Topic configured' : 'Set TALIXIO_NTFY_URL'],
        ]];
    }

    private function activity(): array
    {
        $s = $this->app->store;
        $month = strtotime('first day of this month 00:00');
        return ['usage' => $s->usageByDay(30), 'by_tier' => $s->usageBy('tier', $month), 'by_source' => $s->usageBy('source', $month),
            'by_model' => $s->usageBy('model', $month), 'budget' => $this->app->budget()->status(), 'tools' => $s->toolLog(100),
            'memory' => [
                'messages' => (int)$s->q('SELECT COUNT(*) FROM messages')->fetchColumn(),
                'summaries' => (int)$s->q('SELECT COUNT(*) FROM summaries')->fetchColumn(),
                'docs' => (int)$s->q('SELECT COUNT(*) FROM docs')->fetchColumn(),
                'embedded' => (int)$s->q('SELECT COUNT(*) FROM embeddings')->fetchColumn(),
            ]];
    }

    private function chat(string $text): array
    {
        $text = trim($text);
        if ($text === '') $this->fail(400, 'empty message');
        set_time_limit(0);
        $before = $this->app->store->maxMessageId();
        $this->app->agent()->chat('web', $text);
        return ['messages' => $this->app->store->messagesAfter($before)];
    }

    private function taskAction(int $id, string $action): array
    {
        $s = $this->app->store;
        $t = $s->task($id) ?? $this->fail(404, 'no such task');
        if ($action === 'delete') { $s->deleteTask($id); return ['ok' => true]; }
        if ($t['enabled']) { $s->updateTask($id, ['enabled' => 0]); return ['ok' => true]; }
        $next = $t['cron'] ? Cron::next($t['cron'], time()) : (int)$t['next_run'];
        if (!$t['cron'] && $next < time()) $this->fail(400, 'This one-off task is in the past. Ask the assistant to reschedule it.');
        $s->updateTask($id, ['enabled' => 1, 'next_run' => $next]);
        return ['ok' => true];
    }

    private function startSession(): void
    {
        $dir = $this->app->cfg->dataDir . '/sessions';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        session_save_path($dir);
        session_name('talixio');
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'httponly' => true, 'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS'])]);
        ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
        session_start();
    }

    private function login(string $password): void
    {
        $expected = $this->app->cfg->webPassword;
        if ($expected === '' || !hash_equals($expected, $password)) {
            sleep(2); // slow down guessing
            $this->fail(401, 'Wrong password.');
        }
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $this->json(['ok' => true]);
    }

    private function logout(): array
    {
        session_start();
        $_SESSION = [];
        session_destroy();
        return ['ok' => true];
    }

    private function fail(int $code, string $msg): never
    {
        $this->json(['error' => $msg], $code);
        exit;
    }

    private function json(array $data, int $code = 200): void
    {
        while (ob_get_level() > 0) {
            $stray = ob_get_clean();
            if ($stray !== '' && $stray !== false) Log::warn('web: dropped unexpected output: ' . mb_substr(trim($stray), 0, 300));
        }
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
