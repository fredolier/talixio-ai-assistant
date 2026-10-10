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
        if (preg_match('#^/cal/tasks-([0-9a-f]{40})\.ics$#', $path, $cm) && $method === 'GET') { $this->tasksIcs($cm[1]); return; } // calendar apps have no session
        if (preg_match('#^/s/([A-Za-z0-9_-]{20,64})(?:/(view|download|thumb|zip)(?:/(\d+))?)?/?$#', $path, $sm)) { $this->sharePage($sm[1], $sm[2] ?? '', (int)($sm[3] ?? 0), $method); return; } // links for people outside
        if ($path === '/' || !str_starts_with($path, '/api/')) {
            header('Cache-Control: no-cache');
            header('Content-Type: text/html; charset=utf-8');
            // previews of documents run in sandboxed srcdoc frames that inherit this policy: no inline scripts, web pictures and styles allowed
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:");
            $html = (string)file_get_contents(dirname(__DIR__) . '/web/public/app.html');
            $html = preg_replace('/<html lang="en">/', '<html lang="en" data-lang="' . htmlspecialchars($this->app->cfg->lang) . '">', $html, 1); // the interface language (i18n.js)
            if ($this->app->cfg->lang === 'auto') I18n::remember($this->app, (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            Shares::remember($this->app); // the address for links sent outside
            // the scripts' address carries the version (v/<version>/app.js, served by router.php): a page always gets the
            // scripts of its own version, never older ones from an installed app's cache, even an older service worker's
            $html = preg_replace('/(<script src="|<link rel="stylesheet" href=")((?:i18n\/)?[a-z0-9]+\.(?:js|css))"/', '$1v/' . self::assetVersion() . '/$2"', $html);
            echo preg_replace('/<head>/', '<head><base href="' . htmlspecialchars(Config::basePath() . '/') . '">', $html, 1); // every address in the app is relative to it
            return;
        }
        if ($path === '/api/google/callback' && $method === 'GET') { $this->googleCallback(); return; } // Google's redirect carries no session cookie (SameSite)
        if ($path === '/api/spotify/callback' && $method === 'GET') { $this->spotifyCallback(); return; }
        if ($path === '/api/mcp/oauth/callback' && $method === 'GET') { $this->mcpOAuthCallback(); return; }
        ob_start(); // anything printed by accident is dropped, not mixed into the JSON
        $this->startSession();
        try {
            if ($method === 'POST' && ($_SERVER['HTTP_X_TALIXIO'] ?? '') !== '1') $this->fail(403, 'missing request header');
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            if ($path === '/api/login' && $method === 'POST') { $this->login((string)($body['password'] ?? ''), (string)($body['code'] ?? '')); return; }
            if (empty($_SESSION['ok']) || ($_SESSION['gen'] ?? '') !== $this->auth()->generation()) $this->fail(401, 'Please sign in.');
            session_write_close(); // don't hold the session lock during long model calls
            if ($path === '/api/chat' && $method === 'POST' && !empty($body['stream'])) { $this->chatStream($body); return; }
            if ($path === '/api/files' && $method === 'POST') { $this->json($this->upload()); return; }
            // the file name at the end of the URL keeps its extension in the address bar, tabs and "save as"
            if (preg_match('#^/api/files/(\d+)(?:/file/[^/]+)?$#', $path, $fm) && $method === 'GET') { $this->serveFile((int)$fm[1], !empty($_GET['download'])); return; }
            if (preg_match('#^/api/files/(\d+)/view(?:/[^/]+)?$#', $path, $fm) && $method === 'GET') { $this->serveView((int)$fm[1]); return; }
            if (preg_match('#^/api/files/(\d+)/pdf(?:/[^/]+)?$#', $path, $fm) && $method === 'GET') { $this->serveOfficePdf((int)$fm[1]); return; }
            if (preg_match('#^/api/files/(\d+)/(thumb|page/(\d+))$#', $path, $fm) && $method === 'GET') { $this->servePreview((int)$fm[1], (int)($fm[3] ?? 0)); return; }
            if ($path === '/api/library/zip' && $method === 'GET') { $this->serveZip((string)($_GET['folder'] ?? '/')); return; }
            if ($path === '/api/privacy/journal.csv' && $method === 'GET') { $this->privacyCsv(); return; }
            if ($path === '/api/privacy/register' && $method === 'GET') {
                header('Content-Type: text/markdown; charset=utf-8'); header('Cache-Control: no-store');
                header('Content-Disposition: attachment; filename="record-of-processing-' . date('Y-m-d') . '.md"');
                echo $this->app->privacy()->register(); return;
            }
            if ($path === '/api/share/claim' && $method === 'POST') { $this->json($this->shareClaim((string)($body['token'] ?? ''))); return; }
            $this->json($this->route($method, $path, $body));
        } catch (Conflict $e) {
            $this->json(['error' => $e->getMessage(), 'conflict' => true], 409);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
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
            $path === '/api/messages' => (function () use ($s) {
                $space = (int)($_GET['space'] ?? 0);
                $list = isset($_GET['before']) ? $s->messagesBefore((int)$_GET['before'], 100, $space) : $s->messagesAfter((int)($_GET['after'] ?? 0), 200, $space);
                if (!empty($_GET['read'])) $this->app->spaces()->markRead($space); // the user is looking at this conversation
                return ['messages' => $list, 'first_id' => (int)$s->q('SELECT COALESCE(MIN(id),0) FROM messages WHERE space = ?', [$space])->fetchColumn(),
                    'unread' => array_sum(array_map(fn($x) => $x['id'] !== $space ? $x['unread'] : 0, $this->app->spaces()->all()))];
            })(),
            $path === '/api/messages/search' => ['results' => $this->searchMessages((string)($_GET['q'] ?? ''), (int)($_GET['space'] ?? 0))],
            $path === '/api/spaces' && $m === 'GET' => ['spaces' => $this->app->spaces()->all(true), 'colors' => Spaces::COLORS],
            $path === '/api/spaces' && $m === 'POST' => ['space' => $this->app->spaces()->save($b)],
            (bool)preg_match('#^/api/spaces/(\d+)$#', $path, $mm) && $m === 'POST' => ['space' => $this->app->spaces()->save($b, (int)$mm[1])],
            (bool)preg_match('#^/api/spaces/(\d+)/delete$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->spaces()->delete((int)$mm[1]); return ['ok' => true]; })(),
            (bool)preg_match('#^/api/spaces/(\d+)/read$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->spaces()->markRead((int)$mm[1]); return ['ok' => true]; })(),
            (bool)preg_match('#^/api/messages/(\d+)/undo$#', $path, $mm) && $m === 'POST' => $this->undoTurn((int)$mm[1]),
            $path === '/api/chat/stop' && $m === 'POST' => (function () use ($s) { $s->set('chat_stop', (string)microtime(true)); return ['ok' => true]; })(),
            $path === '/api/chat' && $m === 'POST' => $this->chat((string)($b['text'] ?? ''), array_map('intval', (array)($b['files'] ?? [])), !empty($b['meeting']), null, (int)($b['space'] ?? 0)),
            $path === '/api/tasks' => ['tasks' => array_map(fn($t) => $t + ['next_text' => $t['next_run'] ? date('D j M, H:i', (int)$t['next_run']) : null,
                'space_name' => (int)$t['space'] ? ($this->app->spaces()->get((int)$t['space'])['name'] ?? null) : null], $s->tasks())],
            (bool)preg_match('#^/api/tasks/(\d+)/(toggle|delete)$#', $path, $mm) && $m === 'POST' => $this->taskAction((int)$mm[1], $mm[2]),
            (bool)preg_match('#^/api/tasks/(\d+)/tier$#', $path, $mm) && $m === 'POST' => (function () use ($mm, $b, $s) {
                $s->task((int)$mm[1]) ?? $this->fail(404, 'no such task');
                if (!in_array($b['tier'] ?? '', Tier::NAMES, true)) $this->fail(400, 'unknown tier');
                $s->updateTask((int)$mm[1], ['tier' => $b['tier']]);
                return ['ok' => true];
            })(),
            $path === '/api/heartbeats' => $this->heartbeats(),
            // email sorting and people
            $path === '/api/mail' => ['enabled' => $this->app->triage()->enabled(), 'mailbox' => $this->app->mailbox()->enabled(), 'labels' => Triage::LABELS,
                'emails' => $this->app->triage()->list(!empty($_GET['all'])), 'last_run' => (int)$s->get('triage_last_uid', '0') > 0],
            $path === '/api/mail/run' && $m === 'POST' => (function () { set_time_limit(300); return ['sorted' => $this->app->triage()->run()]; })(),
            (bool)preg_match('#^/api/mail/(\d+)/label$#', $path, $mm) && $m === 'POST' => (function () use ($mm, $b) { $this->app->triage()->relabel((int)$mm[1], (string)($b['label'] ?? '')); return ['ok' => true]; })(),
            (bool)preg_match('#^/api/mail/(\d+)/(done|open)$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->triage()->setStatus((int)$mm[1], $mm[2]); return ['ok' => true]; })(),
            $path === '/api/contacts' && $m === 'GET' => ['contacts' => $this->app->contacts()->search((string)($_GET['q'] ?? ''), 300)],
            $path === '/api/contacts' && $m === 'POST' => ['contact' => $this->app->contacts()->save($b)],
            (bool)preg_match('#^/api/contacts/(\d+)$#', $path, $mm) && $m === 'GET' => $this->app->contacts()->profile((int)$mm[1]),
            (bool)preg_match('#^/api/contacts/(\d+)$#', $path, $mm) && $m === 'POST' => ['contact' => $this->app->contacts()->save(['id' => (int)$mm[1]] + $b)],
            (bool)preg_match('#^/api/contacts/(\d+)/delete$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->contacts()->delete((int)$mm[1]); return ['ok' => true]; })(),
            $path === '/api/tasks/briefing' && $m === 'POST' => ['task' => $this->briefing($b)],
            (bool)preg_match('#^/api/tasks/(\d+)/run$#', $path, $mm) && $m === 'POST' => (function () use ($mm, $s) {
                set_time_limit(0);
                $t = $s->task((int)$mm[1]) ?? $this->fail(404, 'no such task');
                $reply = $this->app->agent()->runTask($t['title'], $t['prompt'], (string)$t['tier'], (int)$t['space']);
                return ['reply' => $reply, 'space' => (int)$t['space']];
            })(),
            $path === '/api/google' => $this->googleState(),
            $path === '/api/google/connect' && $m === 'POST' => ['url' => $this->app->google()->authUrl(Google::redirectUri())],
            $path === '/api/google/disconnect' && $m === 'POST' => (function () { $this->app->google()->disconnect(); return $this->googleState(); })(),
            $path === '/api/spotify' => $this->spotifyState(),
            $path === '/api/spotify/connect' && $m === 'POST' => ['url' => $this->app->spotify()->authUrl(Spotify::redirectUri())],
            $path === '/api/spotify/disconnect' && $m === 'POST' => (function () { $this->app->spotify()->disconnect(); return $this->spotifyState(); })(),
            $path === '/api/mcp' && $m === 'GET' => $this->connectors(),
            $path === '/api/mcp' && $m === 'POST' => (function () use ($b) { set_time_limit(0); $this->app->mcp()->saveRaw((string)($b['raw'] ?? '')); $this->app->mcp()->refresh(); return $this->connectors(); })(),
            $path === '/api/mcp/oauth/start' && $m === 'POST' => (function () use ($b) {
                $name = (string)($b['server'] ?? '');
                $cfg = $this->app->mcp()->servers()[$name] ?? $this->fail(404, 'no such connector');
                return ['url' => $this->app->mcpOAuth()->start($name, $cfg, $this->app->mcp()->cache()[$name]['auth_meta'] ?? null)];
            })(),
            $path === '/api/mcp/oauth/forget' && $m === 'POST' => (function () use ($b) { set_time_limit(0); $this->app->mcpOAuth()->forget((string)($b['server'] ?? '')); $this->app->mcp()->refresh(); return $this->connectors(); })(),
            $path === '/api/mcp/refresh' && $m === 'POST' => (function () { set_time_limit(0); $this->app->mcp()->refresh(); return $this->connectors(); })(),
            $path === '/api/shell/check' && $m === 'POST' => (function () { try { return ['ok' => true, 'text' => $this->app->shell()->check()]; } catch (\Throwable $e) { return ['ok' => false, 'text' => $e->getMessage()]; } })(),
            $path === '/api/heartbeat/run' && $m === 'POST' => (function () {
                set_time_limit(0);
                if (!$this->app->ws->hasContent('HEARTBEAT.md')) $this->fail(400, 'The checklist (Memory → HEARTBEAT.md) is empty: write what to check first.');
                $msg = $this->app->agent()->heartbeat();
                if ($msg !== null) $this->app->notifier()->notify($this->app->cfg->name, $msg);
                return ['message' => $msg] + $this->heartbeats();
            })(),
            $path === '/api/memory' && $m === 'GET' => ['files' => $this->app->ws->all()],
            $path === '/api/memory/digest' && $m === 'GET' => $this->digest(),
            $path === '/api/memory/compact' && $m === 'POST' => (function () {
                set_time_limit(0);
                $sum = new Summarizer($this->app);
                $did = $sum->compact(time(), 1, 0, true);
                while ($sum->merge(time())) $did = true;
                return ['done' => $did] + $this->digest();
            })(),
            $path === '/api/memory' && $m === 'POST' => (function () use ($b) { $this->app->ws->write((string)$b['file'], (string)$b['content']); return ['ok' => true]; })(),
            $path === '/api/activity' => $this->activity(),
            str_starts_with($path, '/api/board') => $this->board($m, $path, $b),
            $path === '/api/jobs' => ['jobs' => $s->jobs(100, isset($_GET['space']) && $_GET['space'] !== '' ? (int)$_GET['space'] : null, (string)($_GET['tag'] ?? '')), 'tags' => $s->jobTags()],
            (bool)preg_match('#^/api/jobs/(\d+)/tags$#', $path, $mm) && $m === 'POST' => ['job' => $this->app->jobs()->setTags((int)$mm[1], $b['tags'] ?? [])],
            $path === '/api/channels' => $this->channels(),
            $path === '/api/settings' && $m === 'GET' => ['groups' => Settings::describe($this->app->cfg->dataDir)],
            $path === '/api/settings' && $m === 'POST' => $this->saveSettings($b),
            $path === '/api/settings/password' && $m === 'POST' => $this->changePassword($b),
            $path === '/api/security' => ['totp' => $this->auth()->totpEnabled()],
            (bool)preg_match('#^/api/security/totp/(setup|enable|disable)$#', $path, $mm) && $m === 'POST' => $this->totp($mm[1], $b),
            $path === '/api/update' => ['installed' => $this->app->updater()->installed(), 'version' => $this->app->updater()->version(),
                'status' => $this->app->updater()->status(), 'auto' => $this->app->cfg->autoUpdate],
            (bool)preg_match('#^/api/update/(check|update|rollback)$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { $this->app->updater()->request($mm[1]); return ['ok' => true]; })(),
            $path === '/api/doctor' && $m === 'POST' => (function () { set_time_limit(0); return ['results' => (new Doctor(App::fromEnv()))->run()]; })(),
            $path === '/api/skills' => ['skills' => $this->app->skills()->all()],
            $path === '/api/mind' && $m === 'GET' => $this->app->mind()->overview(),
            (bool)preg_match('#^/api/mind/(\d+)$#', $path, $mm) && $m === 'GET' => ['evidence' => $this->app->mind()->evidence((int)$mm[1])],
            (bool)preg_match('#^/api/mind/(\d+)/forget$#', $path, $mm) && $m === 'POST' => ['ok' => $this->app->mind()->forget((int)$mm[1])],
            $path === '/api/mind/reflect' && $m === 'POST' => (function () {
                $mind = $this->app->mind();
                if (!$mind->enabled()) $this->fail(400, 'Learning is off (Settings → Learning, backups, updates).');
                return $mind->overview() + ['done' => $mind->thinkNow(time())];
            })(),
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
            (bool)preg_match('#^/api/jobs/(\d+)/continue$#', $path, $mm) && $m === 'POST' => ['id' => $this->app->jobs()->continue((int)$mm[1], is_numeric($b['max_usd'] ?? null) ? (float)$b['max_usd'] : null, (string)($b['effort'] ?? ''), (string)($b['note'] ?? ''))],
            (bool)preg_match('#^/api/jobs/(\d+)/rerun$#', $path, $mm) && $m === 'POST' => ['id' => $this->app->jobs()->rerun((int)$mm[1], (string)($b['tier'] ?? ''), is_numeric($b['max_usd'] ?? null) ? (float)$b['max_usd'] : null, (string)($b['effort'] ?? ''))],
            $path === '/api/library' => $this->app->library()->list((string)($_GET['folder'] ?? '/')) + ['stats' => $this->app->library()->stats(), 'shared' => $this->app->shares()->sharedIndex(),
                'level' => $this->app->privacy()->folderLevel(Library::normalize((string)($_GET['folder'] ?? '/')))],
            $path === '/api/privacy' => $this->app->privacy()->overview() + ['journal' => $this->app->privacy()->journal()],
            $path === '/api/shares' && $m === 'GET' => ['shares' => $this->app->shares()->list((string)($_GET['scope'] ?? 'active'), isset($_GET['file']) ? (int)$_GET['file'] : null, isset($_GET['folder']) ? (string)$_GET['folder'] : null),
                'base' => $this->app->shares()->baseUrl(),
                'level' => $this->app->privacy()->folderLevel(isset($_GET['file']) ? (string)($this->app->files()->get((int)$_GET['file'])['folder'] ?? '/') : Library::normalize((string)($_GET['folder'] ?? '/')))],
            $path === '/api/shares' && $m === 'POST' => ['share' => $this->app->shares()->create(isset($b['file']) ? ['file' => (int)$b['file']] : ['folder' => (string)($b['folder'] ?? '')], $b)],
            (bool)preg_match('#^/api/shares/(\d+)$#', $path, $mm) && $m === 'POST' => ['share' => $this->app->shares()->update((int)$mm[1], $b)],
            (bool)preg_match('#^/api/shares/(\d+)/revoke$#', $path, $mm) && $m === 'POST' => ['ok' => $this->app->shares()->revoke((int)$mm[1])],
            (bool)preg_match('#^/api/shares/(\d+)/log$#', $path, $mm) => ['log' => $this->app->shares()->accessLog((int)$mm[1])],
            $path === '/api/library/tree' => ['tree' => $this->app->library()->tree(), 'stats' => $this->app->library()->stats()],
            $path === '/api/library/search' => ['results' => $this->app->library()->search((string)($_GET['q'] ?? ''), (string)($_GET['folder'] ?? '/'), 30)],
            $path === '/api/library/folder' && $m === 'POST' => ['folder' => $this->app->library()->createFolder((string)($b['path'] ?? ''))],
            $path === '/api/library/folder/move' && $m === 'POST' => ['folder' => $this->app->library()->moveFolder((string)($b['from'] ?? ''), (string)($b['to'] ?? ''))],
            $path === '/api/library/folder/delete' && $m === 'POST' => ['deleted_files' => $this->app->library()->deleteFolder((string)($b['path'] ?? ''), !empty($b['recursive']))],
            $path === '/api/library/note' && $m === 'POST' => ['file' => $this->fileInfo($this->app->library()->saveNote((string)($b['folder'] ?? Library::NOTES),
                (string)($b['name'] ?? ''), (string)($b['content'] ?? ''), isset($b['id']) ? (int)$b['id'] : null))],
            (bool)preg_match('#^/api/files/(\d+)/text$#', $path, $mm) => $this->fileText((int)$mm[1]),
            (bool)preg_match('#^/api/files/(\d+)/preview$#', $path, $mm) => $this->filePreview((int)$mm[1]),
            (bool)preg_match('#^/api/files/(\d+)/(move|rename|delete|reindex)$#', $path, $mm) && $m === 'POST' => $this->fileAction((int)$mm[1], $mm[2], $b),
            (bool)preg_match('#^/api/files/(\d+)/file-it$#', $path, $mm) && $m === 'POST' => ['file' => $this->fileInfo((new Filing($this->app))->apply((int)$mm[1],
                isset($b['folder']) ? (string)$b['folder'] : null, isset($b['name']) ? (string)$b['name'] : null))],
            (bool)preg_match('#^/api/files/(\d+)/dismiss$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { (new Filing($this->app))->dismiss((int)$mm[1]); return ['ok' => true]; })(),
            (bool)preg_match('#^/api/files/(\d+)/suggest$#', $path, $mm) && $m === 'POST' => (function () use ($mm) { set_time_limit(120); return ['suggestion' => (new Filing($this->app))->suggest((int)$mm[1])]; })(),
            $path === '/api/library/file-all' && $m === 'POST' => (function () {
                $n = 0; $filing = new Filing($this->app);
                foreach ($this->app->library()->list(Library::INBOX)['files'] as $f) if ($f['suggestion']) { $filing->apply($f['id']); $n++; }
                return ['filed' => $n];
            })(),
            $path === '/api/search' => ['results' => array_map(fn($h) => array_intersect_key($h, array_flip(['kind', 'ref', 'ts', 'label', 'text', 'keyword', 'sim'])),
                $this->app->recall()->search((string)($_GET['q'] ?? ''), 20))],
            default => $this->fail(404, 'not found'),
        };
    }

    private function connectors(): array
    {
        $mcp = $this->app->mcp(); $cache = $mcp->cache(); $out = [];
        foreach ($mcp->servers() as $name => $srv) {
            $c = $cache[$name] ?? null;
            $signed = isset($srv['url']) && $this->app->mcpOAuth()->signedIn($name);
            $out[] = ['name' => $name, 'kind' => isset($srv['url']) ? 'remote' : 'local', 'trust' => ($srv['trust'] ?? '') === 'all' ? 'all' : 'read',
                'auth' => $signed ? 'signed_in' : (!empty($c['auth']) || !empty($srv['oauth']) ? 'needed' : null), 'signed_in_at' => $signed ? $this->app->mcpOAuth()->account($name) : null,
                'error' => $c['error'] ?? ($c ? null : 'not checked yet'), 'at' => $c['at'] ?? null,
                'tools' => array_map(fn($t) => ['name' => Mcp::toolName($name, (string)$t['name']), 'title' => (string)($t['title'] ?? $t['name']),
                    'read_only' => !empty($t['annotations']['readOnlyHint'])], $c['tools'] ?? [])];
        }
        return ['raw' => $mcp->raw(), 'servers' => $out, 'shell' => ['mode' => $this->app->cfg->shell, 'installed' => Shell::binary() !== null]];
    }

    /** The morning briefing: one scheduled task, created or updated from the Tasks page. */
    private function briefing(array $b): array
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string)($b['time'] ?? ''), $t)) $this->fail(400, 'Give a time like 07:30.');
        $days = in_array($b['days'] ?? '', ['1-5', '*', '1-6', '0,6'], true) ? $b['days'] : '1-5';
        $cron = (int)$t[2] . ' ' . (int)$t[1] . " * * $days";
        $prompt = "Morning briefing for the user. Gather, in this order, only what is useful today:\n"
            . "1. Today's calendar (calendar_events): times, places, what to prepare or bring.\n"
            . "2. Emails waiting (mail_triage): to answer and to do first, with due dates.\n"
            . "3. Tasks (board_list with all_spaces): overdue, due today and in the next 3 days, blocked ones; then the next routines and reminders (list_tasks).\n"
            . "4. The weather today in the user's town (from USER.md; skip it if unknown) with a quick web search.\n"
            . "5. One line on anything else that matters today (follow-ups promised, birthdays in USER.md...).\n"
            . "Short and easy to scan: a heading per part, bullets, under 200 words, in the user's language. Skip empty parts."
            . (trim((string)($b['extra'] ?? '')) !== '' ? "\nAlso: " . trim((string)$b['extra']) : '');
        $s = $this->app->store;
        $next = Cron::next($cron, time());
        $id = (int)$s->q("SELECT id FROM tasks WHERE title = 'Morning briefing' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id) $s->updateTask($id, ['cron' => $cron, 'prompt' => $prompt, 'next_run' => $next, 'enabled' => 1]);
        else $id = $s->addTask('Morning briefing', $prompt, $cron, $next, 'standard', 0);
        return $s->task($id);
    }

    private function googleState(): array
    {
        $g = $this->app->google(); $t = $g->tokens(); $cals = [];
        if ($g->connected()) { try { $cals = array_column($g->calendars(), 'name'); } catch (\Throwable $e) { $err = $e->getMessage(); } }
        return ['configured' => $g->configured(), 'connected' => $g->connected(), 'email' => $t['email'] ?? '', 'calendars' => $cals, 'error' => $err ?? null, 'redirect' => Google::redirectUri()];
    }

    /** Google sends the user back here after consent (the one-time state proves it was started from the app). */
    private function googleCallback(): void
    {
        $msg = 'connected';
        try {
            if (isset($_GET['error'])) throw new \RuntimeException('Google: ' . $_GET['error']);
            $this->app->google()->finish((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
        } catch (\Throwable $e) {
            Log::warn("google: {$e->getMessage()}");
            $msg = $e->getMessage();
        }
        header('Location: ' . Config::basePath() . '/?google=' . rawurlencode($msg) . '#settings', true, 303);
    }

    /** Tasks board. The app acts as "you" and may change any space's cards. */
    private function board(string $m, string $path, array $b): array
    {
        $bd = $this->app->board();
        $card = fn(array $c) => $c + ['warnings' => $bd->warnings($c)];
        $brief = fn(int $id) => ($x = $bd->get($id)) ? $bd->brief($x) : null;
        if ($path === '/api/board' && $m === 'GET') {
            $f = ['space' => $_GET['space'] ?? null, 'tag' => $_GET['tag'] ?? '', 'q' => $_GET['q'] ?? '', 'done_days' => (int)($_GET['done_days'] ?? 14)];
            $cards = array_map($card, $bd->list($f));
            $codes = [];
            foreach ($cards as $c) foreach ([...$c['depends_on'], ...$c['blocks']] as $d) $codes[$d] ??= $brief($d);
            return ['cards' => $cards, 'statuses' => Board::STATUSES, 'tags' => $bd->tags(($f['space'] ?? '') === '' ? null : (int)$f['space']),
                'refs' => array_filter($codes), 'prefix' => ($f['space'] ?? '') === '' ? null : $bd->prefix((int)$f['space'])];
        }
        if ($path === '/api/board' && $m === 'POST') return ['card' => $card($bd->create($b, (int)($b['space'] ?? 0)))];
        if ($path === '/api/board/portfolio') {
            $cards = array_map($card, $bd->list(['open' => true, 'global' => true, 'space' => isset($_GET['space']) && $_GET['space'] !== '' ? (int)$_GET['space'] : null]));
            $codes = [];
            foreach ($cards as $c) foreach ([...$c['depends_on'], ...$c['blocks']] as $d) $codes[$d] ??= $brief($d);
            return ['cards' => $cards, 'refs' => array_filter($codes)];
        }
        if ($path === '/api/board/calendar') {
            $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date('Y-m-t'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $this->fail(400, 'bad dates');
            $cards = array_map($card, $bd->inRange($from, $to, isset($_GET['space']) && $_GET['space'] !== '' ? (int)$_GET['space'] : null));
            $events = [];
            if (!empty($_GET['events']) && $this->app->calendar()->enabled()) {
                try { $events = array_map(fn($e) => array_intersect_key($e, array_flip(['title', 'start', 'end', 'all_day', 'location', 'calendar'])), $this->app->calendar()->events(strtotime($from), strtotime("$to +1 day"))); }
                catch (\Throwable $e) { $eventsError = $e->getMessage(); }
            }
            return ['cards' => $cards, 'events' => $events, 'events_error' => $eventsError ?? null, 'calendar' => $this->app->calendar()->enabled(),
                'google' => $this->app->google()->connected() && $this->app->cfg->tasksGoogle];
        }
        if ($path === '/api/board/ics' && $m === 'GET') return ['url' => $this->baseUrl() . '/cal/tasks-' . $bd->icsToken() . '.ics'];
        if ($path === '/api/board/ics' && $m === 'POST') return ['url' => $this->baseUrl() . '/cal/tasks-' . $bd->icsToken(true) . '.ics'];
        if ($path === '/api/board/trash') return ['cards' => $bd->list(['trash' => true])];
        if ($path === '/api/board/recent') return ['changes' => $bd->recent(isset($_GET['space']) && $_GET['space'] !== '' ? (int)$_GET['space'] : null)];
        if ($path === '/api/board/find') return ['cards' => array_map($bd->brief(...), array_slice($bd->list(['q' => (string)($_GET['q'] ?? ''), 'done_days' => 365]), 0, 20))];
        if ($path === '/api/board/order' && $m === 'POST') { $bd->setGlobalOrder((array)($b['ids'] ?? [])); return ['ok' => true]; }
        if (preg_match('#^/api/board/undo/(\d+)$#', $path, $mm) && $m === 'POST') return ['card' => $card($bd->undo((int)$mm[1]))];
        if (preg_match('#^/api/board/time/(\d+)/delete$#', $path, $mm) && $m === 'POST') { $bd->deleteTime((int)$mm[1]); return ['ok' => true]; }
        if (!preg_match('#^/api/board/(\d+)(?:/(\w+))?$#', $path, $mm)) $this->fail(404, 'not found');
        $id = (int)$mm[1]; $act = $mm[2] ?? '';
        if ($act === '' && $m === 'GET') {
            $c = $bd->get($id) ?? $this->fail(404, 'no such task');
            return ['card' => $card($c), 'history' => $bd->history($id), 'time' => $bd->timeEntries($id),
                'depends_on' => array_values(array_filter(array_map($brief, $c['depends_on']))), 'blocks' => array_values(array_filter(array_map($brief, $c['blocks'])))];
        }
        if ($m !== 'POST') $this->fail(405, 'method not allowed');
        return match ($act) {
            '' => ['card' => $card($bd->update($id, $b, 'you', isset($b['version']) ? (int)$b['version'] : null))],
            'move' => ['card' => $card($bd->move($id, (string)($b['status'] ?? ''), isset($b['before']) ? (int)$b['before'] : null))],
            'delete' => ['waiting' => $bd->delete($id)],
            'restore' => ['card' => $card($bd->restore($id))],
            'time' => ['card' => $card($bd->addTime($id, $b['hours'] ?? null, (string)($b['day'] ?? ''), (string)($b['note'] ?? '')))],
            'unrank' => (function () use ($bd, $id) { $bd->clearGlobalRank($id); return ['ok' => true]; })(),
            default => $this->fail(404, 'not found'),
        };
    }

    private function baseUrl(): string { return preg_replace('#/api/google/callback$#', '', Google::redirectUri()); }

    /** The private calendar link of the tasks shown in the calendar (the token in the address is the password). */
    private function tasksIcs(string $token): void
    {
        $bd = $this->app->board();
        $saved = $this->app->store->get('tasks_ics_token');
        if ($saved === '' || !hash_equals($saved, $token)) { http_response_code(404); echo 'Not found'; return; }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        echo $bd->ics();
    }

    /** Back from a connector's sign-in page (no session cookie here: the one-time state proves it was started from the app). */
    private function mcpOAuthCallback(): void
    {
        $msg = '';
        try {
            if (isset($_GET['error'])) throw new \RuntimeException('sign-in cancelled: ' . $_GET['error'] . (isset($_GET['error_description']) ? ' (' . $_GET['error_description'] . ')' : ''));
            $name = $this->app->mcpOAuth()->finish((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
            set_time_limit(0);
            $cache = $this->app->mcp()->refresh();
            $msg = !empty($cache[$name]['error']) ? "$name: signed in, but " . $cache[$name]['error'] : "connected:$name";
        } catch (\Throwable $e) {
            Log::warn("mcp oauth: {$e->getMessage()}");
            $msg = $e->getMessage();
        }
        header('Location: ' . Config::basePath() . '/?mcp=' . rawurlencode($msg) . '#settings', true, 303);
    }

    private function spotifyState(): array
    {
        $sp = $this->app->spotify(); $t = $sp->tokens(); $devices = [];
        if ($sp->connected()) { try { $devices = array_column($sp->devices(), 'name'); } catch (\Throwable $e) { $err = $e->getMessage(); } }
        return ['configured' => $sp->configured(), 'connected' => $sp->connected(), 'name' => $t['name'] ?? '', 'premium' => $t['premium'] ?? null,
            'devices' => $devices, 'error' => $err ?? null, 'redirect' => Spotify::redirectUri()];
    }

    /** Spotify sends the user back here after consent (the one-time state proves it was started from the app). */
    private function spotifyCallback(): void
    {
        $msg = 'connected';
        try {
            if (isset($_GET['error'])) throw new \RuntimeException('Spotify: ' . $_GET['error']);
            $this->app->spotify()->finish((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
        } catch (\Throwable $e) {
            Log::warn("spotify: {$e->getMessage()}");
            $msg = $e->getMessage();
        }
        header('Location: ' . Config::basePath() . '/?spotify=' . rawurlencode($msg) . '#settings', true, 303);
    }

    private function heartbeats(): array
    {
        $c = $this->app->cfg; $last = (int)$this->app->store->get('heartbeat_last', '0');
        return ['checks' => $this->app->store->heartbeats(60), 'minutes' => $c->heartbeatMinutes, 'checklist' => $this->app->ws->hasContent('HEARTBEAT.md'),
            'next' => $c->heartbeatMinutes > 0 && $this->app->ws->hasContent('HEARTBEAT.md') ? max(time(), $last + $c->heartbeatMinutes * 60) : null];
    }

    private function status(): array
    {
        $c = $this->app->cfg;
        $seen = (int)$this->app->store->get('worker_seen', '0');
        return ['name' => $c->name, 'provider' => $c->provider, 'model' => $c->tier('standard')->label(), 'timezone' => $c->timezone,
            'tiers' => array_map(fn(Tier $t) => $t->label(), $c->tiers), 'budget' => $this->app->budget()->status(),
            'embeddings' => $c->embeddings, 'max_parallel_jobs' => $c->maxParallelJobs, 'job_max_usd' => $c->jobMaxUsd, 'job_max_usd_limit' => $c->jobMaxUsdLimit, 'job_effort' => $c->jobEffort,
            'version' => Version::get()['number'], 'version_label' => Version::get()['label'],
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
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        echo str_replace('__VERSION__', self::assetVersion(), (string)file_get_contents(dirname(__DIR__) . '/web/sw.template.js'));
    }

    /** A fingerprint of the app's files: the page asks for its scripts with it, and the service worker caches them under it. */
    public static function assetVersion(): string
    {
        static $v = null;
        if ($v !== null) return $v;
        $root = dirname(__DIR__);
        $sig = '';
        foreach (array_merge(glob("$root/web/public/*") ?: [], glob("$root/web/public/i18n/*") ?: [], ["$root/web/sw.template.js", "$root/VERSION.json"]) as $f) $sig .= $f . @filemtime($f) . @filesize($f);
        return $v = substr(sha1($sig), 0, 12);
    }

    /**
     * Share target: content shared from another app (photo, PDF, voice note, link, text) arrives here.
     * It is kept in a quarantined inbox (limited size, deleted after an hour) and only becomes a
     * message once the signed-in user claims it in the app and presses Send.
     */
    private function shareInbox(): void
    {
        // the session cookie is not always sent with a share (it comes from another app): without it,
        // shares are small and rate-limited, so nobody can fill the disk by posting here
        $signedIn = false;
        if (isset($_COOKIE['talixio'])) { $this->startSession(); $signedIn = !empty($_SESSION['ok']) && ($_SESSION['gen'] ?? '') === $this->auth()->generation(); session_write_close(); }
        $ip = Auth::clientIp();
        if (!$signedIn) {
            if ($this->auth()->count("share:$ip", 3600) >= Auth::MAX_SHARES_HOUR) {
                http_response_code(429);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Too many shares in a short time. Open the app and attach the file there.';
                return;
            }
            $this->auth()->hit("share:$ip");
        }
        $maxBytes = $signedIn ? 200_000_000 : 25_000_000;
        $inbox = $this->app->cfg->dataDir . '/share-inbox';
        if (!is_dir($inbox)) mkdir($inbox, 0700, true);
        foreach (glob("$inbox/*", GLOB_ONLYDIR) ?: [] as $d) if (filemtime($d) < time() - 3600) exec('rm -rf ' . escapeshellarg($d));
        $pending = glob("$inbox/*", GLOB_ONLYDIR) ?: [];
        if (count($pending) >= 5) { usort($pending, fn($a, $b) => filemtime($a) <=> filemtime($b)); exec('rm -rf ' . escapeshellarg($pending[0])); }
        $token = bin2hex(random_bytes(16));
        $dir = "$inbox/$token"; mkdir($dir, 0700);
        $text = trim(implode("\n", array_filter([trim((string)($_POST['title'] ?? '')), trim((string)($_POST['text'] ?? '')), trim((string)($_POST['url'] ?? ''))], 'strlen')));
        $files = []; $total = 0; $skipped = 0;
        $up = $_FILES['files'] ?? null;
        if ($up) {
            $names = (array)$up['name']; $tmps = (array)$up['tmp_name']; $errs = (array)$up['error']; $types = (array)$up['type']; $sizes = (array)$up['size'];
            foreach ($names as $i => $name) {
                if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) { if (($errs[$i] ?? 1) !== UPLOAD_ERR_NO_FILE) $skipped++; continue; }
                if (count($files) >= 10 || $total + (int)$sizes[$i] > $maxBytes) { $skipped++; continue; }
                $total += (int)$sizes[$i];
                $n = count($files);
                if (move_uploaded_file($tmps[$i], "$dir/$n")) $files[] = ['name' => (string)$name, 'type' => (string)($types[$i] ?? '')];
            }
        }
        file_put_contents("$dir/meta.json", json_encode(['text' => mb_substr($text, 0, 20000), 'files' => $files, 'skipped' => $skipped, 'max_mb' => intdiv($maxBytes, 1_000_000)]));
        header('Location: ' . Config::basePath() . '/?share=' . $token . '#chat', true, 303);
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
            $id = $this->app->files()->save("$dir/$i", $f['name'] ?: 'shared-file', $f['type'], Library::INBOX);
            $out[] = array_intersect_key($this->app->files()->get($id), array_flip(['id', 'name', 'mime', 'kind', 'size', 'duration']));
        }
        exec('rm -rf ' . escapeshellarg($dir));
        return ['text' => (string)$meta['text'], 'files' => $out, 'skipped' => (int)($meta['skipped'] ?? 0), 'max_mb' => (int)($meta['max_mb'] ?? 0)];
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
        $this->checkSecret($current);
        if (mb_strlen($new) < 12) $this->fail(400, 'The new password must be at least 12 characters.');
        Settings::setPassword($this->app->cfg->dataDir, $new);
        $this->renewSession(); // other devices have to sign in again
        return ['ok' => true];
    }

    /** The current password, for sensitive changes; failures count like failed sign-ins. */
    private function checkSecret(string $password): void
    {
        $ip = Auth::clientIp();
        if (($wait = $this->auth()->lockedFor($ip)) > 0) $this->fail(429, 'Too many wrong passwords. Try again in ' . self::minutes($wait) . '.');
        if (!$this->auth()->checkPassword($password)) { $this->auth()->failed($ip); $this->fail(403, 'The current password is wrong.'); }
    }

    /** Two-step sign-in: setup (new secret, not active yet), enable (with a first code), disable (password and code). */
    private function totp(string $action, array $b): array
    {
        $auth = $this->auth(); $st = $this->app->store; $dir = $this->app->cfg->dataDir;
        if ($action === 'setup') {
            if ($auth->totpEnabled()) $this->fail(400, 'Two-step sign-in is already on.');
            $secret = Auth::newSecret();
            $st->set('totp_pending', $secret);
            return ['secret' => $secret, 'uri' => $auth->otpauthUri($secret)];
        }
        if ($action === 'enable') {
            $secret = $st->get('totp_pending');
            if ($secret === '') $this->fail(400, 'Start the setup again.');
            if (!$auth->checkCode($secret, (string)($b['code'] ?? ''))) $this->fail(400, 'Wrong code. Check the time on your phone and try the next code.');
            Settings::setTotp($dir, $secret);
            $st->set('totp_pending', '');
            return ['ok' => true, 'totp' => true];
        }
        $this->checkSecret((string)($b['password'] ?? ''));
        if (!$auth->checkCode($auth->totpSecret(), (string)($b['code'] ?? ''))) $this->fail(403, 'Wrong code.');
        Settings::setTotp($dir, '');
        return ['ok' => true, 'totp' => false];
    }

    private function auth(): Auth { return $this->auth ??= new Auth($this->app); }
    private ?Auth $auth = null;

    private static function minutes(int $s): string { $m = (int)ceil($s / 60); return $m <= 1 ? 'a minute' : ($m < 120 ? "$m minutes" : (int)ceil($m / 60) . ' hours'); }

    /** Find in the conversation: exact words first (newest first), then matches by meaning. */
    private function searchMessages(string $q, int $space = 0): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $s = $this->app->store; $out = [];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        foreach ($s->q("SELECT id, role, channel, content, ts FROM messages WHERE space = ? AND content LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT 40", [$space, $like])->fetchAll() as $r) {
            $out[(int)$r['id']] = ['id' => (int)$r['id'], 'role' => $r['role'], 'channel' => $r['channel'], 'ts' => (int)$r['ts'], 'snippet' => self::snippet($r['content'], $q), 'exact' => true];
        }
        if (count($out) < 40) {
            try {
                foreach ($this->app->recall()->search($q, 20, PHP_INT_MAX, ['message'], null, $space) as $h) {
                    $id = (int)$h['ref'];
                    if (isset($out[$id]) || !($r = $s->q('SELECT id, role, channel, content, ts FROM messages WHERE id = ?', [$id])->fetch())) continue;
                    $out[$id] = ['id' => $id, 'role' => $r['role'], 'channel' => $r['channel'], 'ts' => (int)$r['ts'], 'snippet' => self::snippet($r['content'], $q), 'exact' => false];
                }
            } catch (\Throwable $e) { Log::warn("message search: {$e->getMessage()}"); }
        }
        return array_values($out);
    }

    private static function snippet(string $text, string $q): string
    {
        $text = preg_replace('/\s+/', ' ', preg_replace('/^\[[^\]]*\]\s*/', '', $text));
        $pos = mb_stripos($text, $q);
        $start = $pos === false ? 0 : max(0, $pos - 80);
        return ($start > 0 ? '…' : '') . mb_substr($text, $start, 240) . (mb_strlen($text) > $start + 240 ? '…' : '');
    }

    /**
     * Take back the last turn (to edit the message or get a new answer): removes the user's message and the
     * answer after it, and returns the message for the composer. Only for the latest turn, when nothing else
     * (a job report, a scheduled task) came after it and no answer is being written.
     */
    private function undoTurn(int $id): array
    {
        $s = $this->app->store;
        $msg = $s->q('SELECT * FROM messages WHERE id = ?', [$id])->fetch();
        if (!$msg || $msg['role'] !== 'user') $this->fail(404, 'No such message.');
        $after = $s->q('SELECT id, role, channel FROM messages WHERE id > ? AND space = ? ORDER BY id', [$id, (int)$msg['space']])->fetchAll();
        foreach ($after as $a) if ($a['role'] !== 'assistant' || $a['channel'] !== $msg['channel']) $this->fail(409, 'Only the latest message can be changed.');
        $lock = fopen($this->app->cfg->dataDir . '/agent.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) $this->fail(409, 'Wait for the answer to finish (or stop it) first.');
        try { $s->deleteMessages([$id, ...array_column($after, 'id')]); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        $ids = json_decode((string)$msg['files'], true) ?: [];
        $files = array_values(array_filter(array_map(fn($f) => ($i = $this->app->files()->get((int)$f)) ? $this->fileInfo($i) : null, $ids)));
        return ['text' => trim(preg_replace('/^\[attached [^\]]*\]$/m', '', (string)$msg['content'])), 'files' => $files, 'removed' => [$id, ...array_map('intval', array_column($after, 'id'))]];
    }

    private function digest(): array
    {
        $s = $this->app->store;
        $levels = [];
        foreach ($s->q('SELECT level, COUNT(*) n FROM summaries WHERE merged_into IS NULL GROUP BY level')->fetchAll() as $r) $levels[(int)$r['level']] = (int)$r['n'];
        return ['digest' => (new Summarizer($this->app))->digest(), 'levels' => (object)$levels,
            'waiting' => (int)$s->q('SELECT COUNT(*) FROM messages WHERE id > ?', [(int)$s->get('summarized_upto', '0')])->fetchColumn()];
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

    private function chat(string $text, array $files = [], bool $meeting = false, ?callable $onEvent = null, int $space = 0): array
    {
        if (!$this->app->spaces()->get($space)) $this->fail(404, 'This space no longer exists.');
        $text = trim($text);
        if ($text === '' && !$files) $this->fail(400, 'empty message');
        set_time_limit(0);
        ignore_user_abort(true); // finish the turn even if the page is closed
        $before = $this->app->store->maxMessageId();
        $audio = null;
        foreach ($files as $id) { $f = $this->app->files()->get($id); if ($f && $f['kind'] === 'audio') { $audio = $id; break; } }
        if ($audio !== null) {
            if ($meeting) $text = trim("meeting $text");
            $this->app->agent()->audio('web', $audio, $text, $onEvent, $space);
        } else {
            $this->app->agent()->chat('web', $text, 'standard', $files, $onEvent, $space);
        }
        $this->app->spaces()->markRead($space);
        return ['messages' => $this->app->store->messagesAfter($before, 200, $space)];
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
                fn(array $e) => $send($e['type'], $e), (int)($b['space'] ?? 0));
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
        $id = $this->app->files()->save($f['tmp_name'], (string)$f['name'], (string)($f['type'] ?? ''), (string)($_POST['folder'] ?? Library::INBOX));
        return ['file' => $this->fileInfo($this->app->files()->get($id))];
    }

    private function fileInfo(array $f): array
    {
        return array_intersect_key($f, array_flip(['id', 'name', 'mime', 'kind', 'size', 'duration', 'folder', 'status', 'note', 'created']));
    }

    private function fileText(int $id): array
    {
        $f = $this->app->files()->get($id) ?? $this->fail(404, 'not found');
        $text = $f['status'] === 'ready' || $f['status'] === 'failed' ? (string)$f['text'] : '';
        if ($f['note'] || ($f['kind'] === 'text' && $f['size'] < 2_000_000)) $text = (string)file_get_contents($f['path']); // the source, for editing
        return ['file' => $this->fileInfo($f), 'text' => mb_substr($text, 0, 200000), 'truncated' => mb_strlen($text) > 200000,
            'pages' => in_array($f['kind'], ['pdf', 'office'], true) ? ($this->app->files()->pdfPageCount($id) ?: null) : null];
    }

    /**
     * The rendered page as data: the app shows it in a sandboxed srcdoc frame (no scripts, own origin),
     * so headers a reverse proxy adds (X-Frame-Options, Content-Type rules...) cannot break previews.
     */
    private function filePreview(int $id): array
    {
        $f = $this->app->files()->get($id) ?? $this->fail(404, 'not found');
        if (!is_file($f['path'])) $this->fail(404, 'The file is missing on the server.');
        if (!Preview::renders($f)) $this->fail(400, 'No page preview for this kind of file.');
        return ['file' => $this->fileInfo($f), 'html' => (new Preview($this->app))->render($f)];
    }

    private function fileAction(int $id, string $action, array $b): array
    {
        $lib = $this->app->library();
        return match ($action) {
            'move' => ['file' => $this->fileInfo($lib->moveFile($id, (string)($b['folder'] ?? '')))],
            'rename' => ['file' => $this->fileInfo($lib->renameFile($id, (string)($b['name'] ?? '')))],
            'delete' => (function () use ($lib, $id) { $lib->deleteFile($id); return ['ok' => true]; })(),
            'reindex' => (function () use ($id) {
                $this->app->store->q("UPDATE files SET status = 'pending', text = NULL WHERE id = ? AND status != 'processing'", [$id]);
                return ['file' => $this->fileInfo($this->app->files()->get($id) ?? $this->fail(404, 'not found'))];
            })(),
        };
    }

    /**
     * Images, PDFs, audio, video and plain text are shown inline (?download=1 forces a download);
     * anything else is downloaded, never rendered on this origin. Supports Range (seeking in audio and video).
     */
    private function serveFile(int $id, bool $download = false): void
    {
        $f = $this->app->files()->get($id);
        if (!$f || !is_file($f['path'])) $this->fail(404, 'not found');
        $mime = $f['mime'];
        $safe = !str_contains($mime, 'svg') && !str_contains($mime, 'html') && !str_contains($mime, 'xml');
        $inline = !$download && $safe && (in_array($f['kind'], ['image', 'pdf', 'audio'], true) || ($f['kind'] === 'text' && preg_match('#^text/(plain|markdown|csv)#', $mime)));
        $type = $inline ? ($f['kind'] === 'text' ? preg_replace('/;.*/', '', $mime) . '; charset=utf-8' : $mime) : 'application/octet-stream';
        // PDFs need the browser's own viewer, which a sandboxed response blocks; they still get no scripts or network access
        $this->sendPath($f['path'], $type, self::disposition($inline ? 'inline' : 'attachment', $f['name']),
            $f['kind'] === 'pdf' && $inline ? "default-src 'none'; object-src 'self'" : "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    }

    /** A readable page for documents (Markdown, HTML, Office, CSV, email...); media open natively. */
    private function serveView(int $id): void
    {
        $f = $this->app->files()->get($id);
        if (!$f || !is_file($f['path'])) $this->fail(404, 'not found');
        if (!Preview::renders($f)) { $this->serveFile($id); return; }
        $html = (new Preview($this->app))->render($f);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache');
        // own opaque origin, no scripts, no forms; pictures and fonts of saved pages may load
        header("Content-Security-Policy: default-src 'none'; img-src data: https:; style-src 'unsafe-inline' https:; font-src data: https:; sandbox allow-popups allow-popups-to-escape-sandbox");
        header('Referrer-Policy: no-referrer');
        echo $html;
    }

    /** An Office file as PDF (LibreOffice), shown by the browser's own viewer. */
    private function serveOfficePdf(int $id): void
    {
        $f = $this->app->files()->get($id);
        if (!$f || $f['kind'] !== 'office' || !Files::officeBinary()) $this->fail(404, 'not found');
        try { $pdf = $this->app->files()->officePdf($f); } catch (\Throwable $e) { $this->fail(500, $e->getMessage()); }
        $this->sendPath($pdf, 'application/pdf', self::disposition('inline', preg_replace('/\.[^.]+$/', '', $f['name']) . '.pdf'), "default-src 'none'; object-src 'self'");
    }

    private function servePreview(int $id, int $page): void
    {
        $files = $this->app->files();
        try {
            $p = $page > 0 ? $files->pdfPage($id, $page) : $files->thumb($id);
        } catch (\Throwable $e) {
            $this->fail(404, $e->getMessage());
        }
        if ($p === null) $this->fail(404, 'no preview');
        $this->sendPath($p, 'image/jpeg', 'inline', "default-src 'none'; sandbox");
    }

    private function serveZip(string $folder): void
    {
        set_time_limit(0);
        $folder = Library::normalize($folder);
        try { $tmp = $this->app->files()->zipFolder($folder); }
        catch (\InvalidArgumentException $e) { $this->fail(400, $e->getMessage()); }
        $name = ($folder === '/' ? 'Library' : basename($folder)) . '-' . date('Y-m-d') . '.zip';
        try { $this->sendPath($tmp, 'application/zip', self::disposition('attachment', $name), "default-src 'none'; sandbox", false); }
        finally { @unlink($tmp); }
    }

    // ---- links for people outside (Shares) ----

    /** /s/<token>[/view|download|thumb|zip[/<file id>]]: the shared documents, for someone without an account. */
    private function sharePage(string $token, string $action, int $fileId, string $method): void
    {
        $shares = $this->app->shares();
        $lang = I18n::visitor((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $tr = fn(string $t, array $v = []) => I18n::tl($lang, $t, $v);
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        $s = $shares->byToken($token);
        if (!$s) { $this->shareHtml(404, $lang, $tr('Link not available'), '<p>' . htmlspecialchars($tr('This link has expired or was removed. Ask the person who sent it for a new one.')) . '</p>'); return; }
        $error = '';
        if (!$shares->unlocked($s)) {
            if ($method === 'POST') {
                try { if ($shares->unlock($s, (string)($_POST['password'] ?? ''))) { header('Location: ' . Config::basePath() . "/s/$token", true, 303); return; } $error = $tr('Wrong password.'); }
                catch (\RuntimeException $e) { $error = $tr($e->getMessage()); }
            }
            $this->shareHtml($error ? 403 : 200, $lang, $tr('Protected documents'), '<form method="post" class="pw"><label for="pw">' . htmlspecialchars($tr('This link is protected. Password:')) . '</label>'
                . '<input id="pw" name="password" type="password" autocomplete="off" required autofocus><button type="submit">' . htmlspecialchars($tr('Open')) . '</button>'
                . ($error ? '<p class="err">' . htmlspecialchars($error) . '</p>' : '') . '</form>');
            return;
        }
        $files = $shares->files($s);
        $base = Config::basePath() . "/s/$token";
        if ($action !== '') {
            if ($action === 'zip') {
                if (!$s['download'] || $s['folder'] === null) { $this->shareHtml(403, $lang, $tr('Not allowed'), '<p>' . htmlspecialchars($tr('This link is for viewing only.')) . '</p>'); return; }
                $shares->logAccess($s, 'zip');
                $this->serveZip((string)$s['folder']);
                return;
            }
            $f = $files[$fileId] ?? null;
            if (!$f) { $this->shareHtml(404, $lang, $tr('Not found'), '<p>' . htmlspecialchars($tr('This document is not part of the link.')) . '</p>'); return; }
            if ($action === 'thumb') { $this->servePreview($fileId, 0); return; }
            if ($action === 'download') {
                if (!$s['download']) { $this->shareHtml(403, $lang, $tr('Not allowed'), '<p>' . htmlspecialchars($tr('This link is for viewing only.')) . '</p>'); return; }
                $shares->logAccess($s, 'download', $fileId);
                $this->serveFile($fileId, true);
                return;
            }
            if (!self::shareViewable($f) && !$s['download']) { $this->shareHtml(403, $lang, $tr('Not allowed'), '<p>' . htmlspecialchars($tr('This kind of document can only be downloaded, and this link is for viewing only.')) . '</p>'); return; }
            $shares->logAccess($s, 'view', $fileId);
            if ($f['kind'] === 'office' && Files::officeBinary()) { $this->serveOfficePdf($fileId); return; }
            if (Preview::renders($f)) { $this->serveView($fileId); return; }
            $this->serveFile($fileId);
            return;
        }
        $shares->logAccess($s, 'open');
        $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES);
        $rows = '';
        foreach ($files as $f) {
            $sub = $s['folder'] !== null ? ltrim(mb_substr($f['folder'], mb_strlen((string)$s['folder'])), '/') : '';
            $thumb = in_array($f['kind'], ['image', 'pdf', 'office'], true) ? '<img class="th" loading="lazy" alt="" src="' . $h("$base/thumb/{$f['id']}") . '">' : '<span class="th ext">' . $h(strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION) ?: '?')) . '</span>';
            $acts = (self::shareViewable($f) ? '<a class="btn" href="' . $h("$base/view/{$f['id']}") . '" target="_blank" rel="noopener">' . $h($tr('View')) . '</a>' : '')
                . ($s['download'] ? '<a class="btn" href="' . $h("$base/download/{$f['id']}") . '">' . $h($tr('Download')) . '</a>' : '');
            $rows .= "<li>$thumb<div class=\"meta\"><b>" . $h($f['name']) . '</b><span>' . ($sub !== '' ? $h($sub) . ' · ' : '') . $h(self::shareSize((int)$f['size'])) . '</span></div><div class="acts">' . $acts . '</div></li>';
        }
        $title = $s['file_id'] ? (reset($files)['name'] ?? $tr('Document')) : basename((string)$s['folder']);
        $intro = ($s['message'] !== '' ? '<blockquote>' . nl2br($h($s['message'])) . '</blockquote>' : '')
            . '<p class="muted">' . $h($s['expires'] ? $tr('Available until {date}.', ['date' => date($lang === 'fr' ? 'd/m/Y' : 'Y-m-d', (int)$s['expires'])]) : $tr('Shared with you.'))
            . ($s['download'] ? '' : ' ' . $h($tr('View only.'))) . '</p>';
        $all = $s['folder'] !== null && $s['download'] && count($files) > 1 ? '<p><a class="btn primary" href="' . $h("$base/zip") . '">' . $h($tr('Download everything (zip)')) . '</a></p>' : '';
        $this->shareHtml(200, $lang, $title, $intro . $all . ($rows !== '' ? "<ul class=\"files\">$rows</ul>" : '<p class="muted">' . $h($tr('This folder is empty.')) . '</p>'));
    }

    private static function shareViewable(array $f): bool
    {
        $mime = (string)$f['mime'];
        if (str_contains($mime, 'svg') || str_contains($mime, 'xml') && $f['kind'] !== 'office') return false;
        return in_array($f['kind'], ['image', 'pdf', 'audio', 'text', 'office'], true);
    }

    private static function shareSize(int $b): string
    {
        return $b < 1024 ? "$b B" : ($b < 1048576 ? round($b / 1024) . ' KB' : number_format($b / 1048576, 1) . ' MB');
    }

    private function shareHtml(int $code, string $lang, string $title, string $body): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $t = htmlspecialchars($title);
        echo "<!doctype html><html lang=\"$lang\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><meta name=\"robots\" content=\"noindex\"><title>$t</title><style>"
            . ':root{--bg:#f2f5f4;--card:#fff;--ink:#16211e;--muted:#5d6b67;--line:#dfe6e3;--accent:#0e7c6b;--accent-ink:#fff;color-scheme:light dark}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#0d1412;--card:#141d1a;--ink:#e6efec;--muted:#93a39e;--line:#26332f;--accent:#3dc9a7;--accent-ink:#06231c}}'
            . 'body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:760px;margin:0 auto;padding:32px 16px}'
            . 'h1{font-size:1.4rem;margin:0 0 8px;overflow-wrap:anywhere}.muted{color:var(--muted);font-size:.92rem}blockquote{margin:12px 0;padding:10px 14px;background:var(--card);border-left:3px solid var(--accent);border-radius:8px}'
            . '.files{list-style:none;padding:0;margin:16px 0;display:flex;flex-direction:column;gap:8px}.files li{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 12px}'
            . '.th{width:48px;height:48px;object-fit:cover;border-radius:6px;flex:none;background:var(--bg)}.ext{display:grid;place-items:center;font-size:.7rem;font-weight:700;color:var(--muted)}'
            . '.meta{flex:1;min-width:0;display:flex;flex-direction:column}.meta b{overflow-wrap:anywhere}.meta span{color:var(--muted);font-size:.85rem}.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}'
            . '.btn{display:inline-block;padding:6px 12px;border:1px solid var(--line);border-radius:8px;color:var(--ink);text-decoration:none;font-size:.9rem;background:var(--card)}.btn.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}'
            . '.pw{display:flex;flex-direction:column;gap:8px;max-width:340px}input{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink)}'
            . 'button{font:inherit;padding:8px 12px;border:0;border-radius:8px;background:var(--accent);color:var(--accent-ink);cursor:pointer}.err{color:#b42318}footer{margin-top:32px;color:var(--muted);font-size:.8rem}'
            . "@media (max-width:520px){.files li{flex-wrap:wrap}.acts{width:100%;justify-content:flex-start}}</style></head><body><main><h1>$t</h1>$body<footer>"
            . htmlspecialchars(I18n::tl($lang, 'Shared with Talixio.')) . '</footer></main></body></html>';
    }

    /** The journal of requests to the outside (90 days), as CSV: never the content, only who, what for and how much. */
    private function privacyCsv(): void
    {
        header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="talixio-outside-requests-' . date('Y-m-d') . '.csv"');
        $names = array_column($this->app->spaces()->all(true), 'name', 'id');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['time', 'service', 'purpose', 'bytes', 'space', 'level', 'blocked']);
        foreach ($this->app->privacy()->journalRows() as $r) {
            fputcsv($out, [date('Y-m-d H:i:s', (int)$r['ts']), $r['service'], $r['purpose'], $r['bytes'], (int)$r['space'] ? ($names[(int)$r['space']] ?? $r['space']) : 'General', $r['level'], $r['blocked'] ? 'yes' : 'no']);
        }
        fclose($out);
    }

    /** Content-Disposition with an ASCII fallback name and the exact UTF-8 one. */
    private static function disposition(string $type, string $name): string
    {
        $ascii = trim(preg_replace('/[^A-Za-z0-9._ ()-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: ''), '_ ') ?: 'file';
        return "$type; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name);
    }

    /** Send a file with ETag revalidation and single Range support. */
    private function sendPath(string $path, string $type, string $disposition, string $csp, bool $cache = true): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $size = filesize($path);
        $etag = '"' . substr(md5($path . '|' . $size . '|' . filemtime($path)), 0, 16) . '"';
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . $disposition);
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: ' . $csp);
        header('Accept-Ranges: bytes');
        if ($cache) {
            header('Cache-Control: private, no-cache');
            header('ETag: ' . $etag);
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); return; }
        } else {
            header('Cache-Control: no-store');
        }
        $start = 0; $end = $size - 1;
        if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
            if ($r[1] === '') { $start = max(0, $size - (int)$r[2]); }
            else { $start = (int)$r[1]; if ($r[2] !== '') $end = min($end, (int)$r[2]); }
            if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); return; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) return;
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, (int)min(262144, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk; flush();
            $left -= strlen($chunk);
        }
        fclose($fh);
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
        $base = Config::basePath();
        session_name($base === '' ? 'talixio' : 'talixio_' . substr(sha1($base), 0, 8)); // instances under one address keep separate sessions
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'httponly' => true, 'samesite' => 'Strict', 'path' => $base . '/',
            'secure' => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS'])]);
        ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
        session_start();
    }

    /** Password, then the authenticator code when two-step sign-in is on. Failed attempts lock the address for a while. */
    private function login(string $password, string $code): void
    {
        $auth = $this->auth(); $ip = Auth::clientIp();
        if (($wait = $auth->lockedFor($ip)) > 0) {
            header('Retry-After: ' . $wait);
            $this->fail(429, 'Too many failed attempts. Try again in ' . self::minutes($wait) . '.');
        }
        if (!$auth->hasPassword() || !$auth->checkPassword($password)) { $auth->failed($ip); $this->fail(401, 'Wrong password.'); }
        if ($auth->totpEnabled()) {
            if (trim($code) === '') { $this->json(['error' => 'Enter the 6-digit code from your authenticator app.', 'need_code' => true], 401); return; }
            if (!$auth->checkCode($auth->totpSecret(), $code)) { $auth->failed($ip); $this->json(['error' => 'Wrong code.', 'need_code' => true], 401); return; }
        }
        $auth->clear("login:$ip");
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['gen'] = $auth->generation();
        $this->json(['ok' => true]);
    }

    /** After a password change: a fresh session id for this device, marked with the new password generation. */
    private function renewSession(): void
    {
        session_start();
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['gen'] = $this->auth()->generation();
        session_write_close();
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
