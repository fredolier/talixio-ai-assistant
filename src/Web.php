<?php
declare(strict_types=1);
namespace Talixio;

/** JSON API for the web app. Single user, password login, session cookie. */
final class Web
{
    public function __construct(private App $app) {}

    public function handle(string $method, string $path): void
    {
        if ($path === '/sw.js') { $this->serviceWorker(); return; }
        if ($path === '/share' && $method === 'POST') { $this->shareInbox(); return; }
        if ($path === '/' || !str_starts_with($path, '/api/')) {
            header('Cache-Control: no-cache');
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
            if ($path === '/api/chat' && $method === 'POST' && !empty($body['stream'])) { $this->chatStream($body); return; }
            if ($path === '/api/files' && $method === 'POST') { $this->json($this->upload()); return; }
            if (preg_match('#^/api/files/(\d+)$#', $path, $fm) && $method === 'GET') { $this->serveFile((int)$fm[1]); return; }
            if ($path === '/api/share/claim' && $method === 'POST') { $this->json($this->shareClaim((string)($body['token'] ?? ''))); return; }
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
            $path === '/api/chat' && $m === 'POST' => $this->chat((string)($b['text'] ?? ''), array_map('intval', (array)($b['files'] ?? [])), !empty($b['meeting'])),
            $path === '/api/tasks' => ['tasks' => array_map(fn($t) => $t + ['next_text' => $t['next_run'] ? date('D j M, H:i', (int)$t['next_run']) : null], $s->tasks())],
            (bool)preg_match('#^/api/tasks/(\d+)/(toggle|delete)$#', $path, $mm) && $m === 'POST' => $this->taskAction((int)$mm[1], $mm[2]),
            $path === '/api/memory' && $m === 'GET' => ['files' => $this->app->ws->all()],
            $path === '/api/memory' && $m === 'POST' => (function () use ($b) { $this->app->ws->write((string)$b['file'], (string)$b['content']); return ['ok' => true]; })(),
            $path === '/api/activity' => $this->activity(),
            $path === '/api/jobs' => ['jobs' => $s->jobs(50)],
            $path === '/api/channels' => $this->channels(),
            $path === '/api/settings' && $m === 'GET' => ['groups' => Settings::describe($this->app->cfg->dataDir)],
            $path === '/api/settings' && $m === 'POST' => $this->saveSettings($b),
            $path === '/api/settings/password' && $m === 'POST' => $this->changePassword($b),
            $path === '/api/update' => ['installed' => $this->app->updater()->installed(), 'version' => $this->app->updater()->version(),
                'status' => $this->app->updater()->status(), 'auto' => $this->app->cfg->autoUpdate],
            (bool)preg_match('#^/api/update/(check|update|rollback)$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->updater()->request($mm[1]); return ['ok' => true]; })(),
            $path === '/api/doctor' && $m === 'POST' => (function () { set_time_limit(0); return ['results' => (new Doctor(App::fromEnv()))->run()]; })(),
            $path === '/api/skills' => ['skills' => $this->app->skills()->all()],
            (bool)preg_match('#^/api/skills/([\w-]+)$#', $path, $mm) && $m === 'GET' => ['content' => $this->app->skills()->raw($mm[1]) ?? $this->fail(404, 'no such skill')],
            (bool)preg_match('#^/api/skills/([\w-]+)$#', $path, $mm) && $m === 'POST' => (function () use ($mm, $b) { $this->app->skills()->writeRaw($mm[1], (string)($b['content'] ?? '')); return ['ok' => true]; })(),
            (bool)preg_match('#^/api/skills/([\w-]+)/delete$#', $path, $mm) && $m === 'POST' => ['ok' => $this->app->skills()->delete($mm[1])],
            $path === '/api/approvals' => ['approvals' => array_map(fn($a) => $a + ['args_decoded' => json_decode($a['args'], true)], $this->app->approvals()->pending())],
            (bool)preg_match('#^/api/approvals/(\d+)/(approve|reject)$#', $path, $mm) && $m === 'POST' => (function () use ($mm) {
                $before = $this->app->store->maxMessageId();
                $msg = $this->app->approvals()->decide((int)$mm[1], $mm[2] === 'approve', 'web');
                return ['message' => $msg, 'messages' => $this->app->store->messagesAfter($before)];
            })(),
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
            'version' => $this->app->updater()->version()['short'] ?? null,
            'worker_online' => time() - $seen < 90, 'search' => $c->search, 'pending_approvals' => count($this->app->approvals()->pending()),
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
             'detail' => $c->smtpHost ? "From {$c->emailFrom}" . ($c->emailTo ? " · notifications to {$c->emailTo}" : '') . ($c->imapHost ? ' · answers ' . implode(', ', $c->emailAllowed) : '') : 'Set it up below: Configuration → Assistant\'s email'],
            ['id' => 'signal', 'name' => 'Signal', 'state' => $notify['signal'], 'error' => $err('signal'), 'chat' => $this->app->signal()->enabled(),
             'detail' => $c->signalNumber ? "Number {$c->signalNumber} · answers " . implode(', ', array_map($mask, $c->signalAllowed)) : 'Install with install.sh --with-signal, register a number, then fill in Configuration → Signal'],
            ['id' => 'calendar', 'name' => 'Calendar', 'state' => $this->app->calendar()->enabled() ? ($this->app->calendar()->canWrite() ? 'on' : 'read only') : 'not configured', 'error' => null, 'chat' => false,
             'detail' => $this->app->calendar()->enabled() ? count($c->calendarIcs) . ' subscription link(s)' . ($c->caldavUrl ? ' · CalDAV (adds events after your approval)' : '') : 'Set it up below: Configuration → Calendar'],
            ['id' => 'mailbox', 'name' => 'Your mailbox', 'state' => $this->app->mailbox()->enabled() ? 'on' : 'not configured', 'error' => null, 'chat' => false,
             'detail' => $this->app->mailbox()->enabled() ? "{$c->mailboxUser}: reads, sorts and drafts; sends only after your approval" : 'Set it up below: Configuration → Your mailbox'],
            ['id' => 'telegram', 'name' => 'Telegram', 'state' => $notify['telegram'], 'error' => $err('telegram'), 'chat' => $c->telegramToken !== '',
             'detail' => $c->telegramToken ? count($c->telegramAllowed) . ' allowed chat(s)' : 'Set it up below: Configuration → Telegram'],
            ['id' => 'ntfy', 'name' => 'ntfy', 'state' => $notify['ntfy'], 'error' => $err('ntfy'), 'chat' => false,
             'detail' => $c->ntfyUrl ? 'Topic configured' : 'Set it up below: Configuration → Notifications'],
        ]];
    }

    /** The service worker, versioned by the app's files so every update refreshes installed apps. */
    private function serviceWorker(): void
    {
        $root = dirname(__DIR__);
        $sig = '';
        foreach (array_merge(glob("$root/web/public/*") ?: [], ["$root/web/sw.template.js", "$root/VERSION.json"]) as $f) $sig .= $f . @filemtime($f) . @filesize($f);
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        echo str_replace('__VERSION__', substr(sha1($sig), 0, 12), (string)file_get_contents("$root/web/sw.template.js"));
    }

    /**
     * Share target: content shared from another app (photo, PDF, voice note, link, text) arrives here.
     * It is kept in a quarantined inbox (limited size, deleted after an hour) and only becomes a
     * message once the signed-in user claims it in the app and presses Send.
     */
    private function shareInbox(): void
    {
        $inbox = $this->app->cfg->dataDir . '/share-inbox';
        if (!is_dir($inbox)) mkdir($inbox, 0700, true);
        foreach (glob("$inbox/*", GLOB_ONLYDIR) ?: [] as $d) if (filemtime($d) < time() - 3600) exec('rm -rf ' . escapeshellarg($d));
        $pending = glob("$inbox/*", GLOB_ONLYDIR) ?: [];
        if (count($pending) >= 5) { usort($pending, fn($a, $b) => filemtime($a) <=> filemtime($b)); exec('rm -rf ' . escapeshellarg($pending[0])); }
        $token = bin2hex(random_bytes(16));
        $dir = "$inbox/$token"; mkdir($dir, 0700);
        $text = trim(implode("\n", array_filter([trim((string)($_POST['title'] ?? '')), trim((string)($_POST['text'] ?? '')), trim((string)($_POST['url'] ?? ''))], 'strlen')));
        $files = []; $total = 0;
        $up = $_FILES['files'] ?? null;
        if ($up) {
            $names = (array)$up['name']; $tmps = (array)$up['tmp_name']; $errs = (array)$up['error']; $types = (array)$up['type']; $sizes = (array)$up['size'];
            foreach ($names as $i => $name) {
                if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK || count($files) >= 10 || ($total += (int)$sizes[$i]) > 100_000_000) continue;
                $n = count($files);
                if (move_uploaded_file($tmps[$i], "$dir/$n")) $files[] = ['name' => (string)$name, 'type' => (string)($types[$i] ?? '')];
            }
        }
        file_put_contents("$dir/meta.json", json_encode(['text' => mb_substr($text, 0, 20000), 'files' => $files]));
        header('Location: /?share=' . $token . '#chat', true, 303);
    }

    /** Signed-in user takes a shared item: files are stored like uploads and prefilled in the composer. */
    private function shareClaim(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) $this->fail(400, 'bad share');
        $dir = $this->app->cfg->dataDir . "/share-inbox/$token";
        $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true);
        if (!is_array($meta)) $this->fail(404, 'This share has expired. Share it again.');
        $out = [];
        foreach ($meta['files'] as $i => $f) {
            if (!is_file("$dir/$i")) continue;
            $id = $this->app->files()->save("$dir/$i", $f['name'] ?: 'shared-file', $f['type']);
            $out[] = array_intersect_key($this->app->files()->get($id), array_flip(['id', 'name', 'mime', 'kind', 'size', 'duration']));
        }
        exec('rm -rf ' . escapeshellarg($dir));
        return ['text' => (string)$meta['text'], 'files' => $out];
    }

    private function saveSettings(array $b): array
    {
        $errors = Settings::save($this->app->cfg->dataDir, (array)($b['changes'] ?? []), array_map('strval', (array)($b['reset'] ?? [])));
        if ($errors) { http_response_code(422); return ['errors' => $errors, 'error' => 'Some values are not valid.']; }
        Settings::apply($this->app->cfg->dataDir);
        return ['ok' => true, 'groups' => Settings::describe($this->app->cfg->dataDir)];
    }

    private function changePassword(array $b): array
    {
        $current = (string)($b['current'] ?? ''); $new = (string)($b['new'] ?? '');
        if (!hash_equals($this->app->cfg->webPassword, $current)) { sleep(2); $this->fail(403, 'The current password is wrong.'); }
        if (mb_strlen($new) < 12) $this->fail(400, 'The new password must be at least 12 characters.');
        Settings::setPassword($this->app->cfg->dataDir, $new);
        return ['ok' => true];
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
                'embedded' => $this->app->recall()->coverage()[0], 'embeddings_model' => $this->app->embeddings->enabled() ? $this->app->embeddings->model() : null,
                'skills' => count($this->app->skills()->all()),
            ], 'backup' => ['last' => (int)$s->get('backup_last', '0') ?: null, 'file' => basename($s->get('backup_last_file')) ?: null, 'keep' => $this->app->cfg->backupKeep]];
    }

    private function chat(string $text, array $files = [], bool $meeting = false, ?callable $onEvent = null): array
    {
        $text = trim($text);
        if ($text === '' && !$files) $this->fail(400, 'empty message');
        set_time_limit(0);
        ignore_user_abort(true); // finish the turn even if the page is closed
        $before = $this->app->store->maxMessageId();
        $audio = null;
        foreach ($files as $id) { $f = $this->app->files()->get($id); if ($f && $f['kind'] === 'audio') { $audio = $id; break; } }
        if ($audio !== null) {
            if ($meeting) $text = trim("meeting $text");
            $this->app->agent()->audio('web', $audio, $text, $onEvent);
        } else {
            $this->app->agent()->chat('web', $text, 'standard', $files, $onEvent);
        }
        return ['messages' => $this->app->store->messagesAfter($before)];
    }

    /** Server-sent events: text as it is written, tool activity, then the stored messages. */
    private function chatStream(array $b): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        $send = function (string $event, array $data): void {
            echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @flush();
        };
        echo ": stream\n\n"; @flush();
        try {
            $result = $this->chat((string)($b['text'] ?? ''), array_map('intval', (array)($b['files'] ?? [])), !empty($b['meeting']),
                fn(array $e) => $send($e['type'], $e));
            $send('done', $result);
        } catch (\Throwable $e) {
            Log::error("web stream: {$e->getMessage()}");
            $send('fail', ['error' => $e->getMessage()]);
        }
    }

    private function upload(): array
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $err = $f['error'] ?? -1;
            $this->fail(400, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'File too large.' : 'Upload failed.');
        }
        $id = $this->app->files()->save($f['tmp_name'], (string)$f['name'], (string)($f['type'] ?? ''));
        $info = $this->app->files()->get($id);
        return ['file' => array_intersect_key($info, array_flip(['id', 'name', 'mime', 'kind', 'size', 'duration']))];
    }

    /** Images, PDFs and audio are shown inline; anything else is downloaded, never rendered on this origin. */
    private function serveFile(int $id): void
    {
        $f = $this->app->files()->get($id);
        if (!$f || !is_file($f['path'])) $this->fail(404, 'not found');
        while (ob_get_level() > 0) ob_end_clean();
        $inline = in_array($f['kind'], ['image', 'pdf', 'audio'], true) && !str_contains($f['mime'], 'svg') && !str_contains($f['mime'], 'html');
        header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($f['path']));
        readfile($f['path']);
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
