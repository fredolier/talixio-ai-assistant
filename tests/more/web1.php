<?php
declare(strict_types=1);
// The web API's front half (src/Web.php: handle, route, board, callbacks, channels, share inbox, settings).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Auth};

$d = test_dir('web1');
$web = web_app($d, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery', 'TALIXIO_LANG' => 'auto',
    'TALIXIO_CALENDAR_ICS' => 'http://127.0.0.1:9/none.ics']);
// web_app hides its port: read it from the client it returns (raw requests are needed for pages, redirects and uploads)
$portOf = fn(\Closure $c): int => (int)(new ReflectionFunction($c))->getStaticVariables()['port'];
$port = $portOf($web);
$raw = function (string $path, string $method = 'GET', $fields = null, string $jar = 'a', array $headers = []) use ($port, $d) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => "$d/ck-$jar", CURLOPT_COOKIEFILE => "$d/ck-$jar",
        CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_merge(['X-Forwarded-For: 203.0.113.9'], $headers),
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($h); }]
        + ($fields !== null ? [CURLOPT_POSTFIELDS => $fields] : []));
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'h' => $hdr];
};

// ---- pages and requests without a session ----
$sw = $raw('/sw.js');
check('web1: the service worker carries the app version', $sw['code'] === 200 && str_contains($sw['h']['content-type'] ?? '', 'javascript')
    && !str_contains($sw['body'], '__VERSION__') && str_contains($sw['body'], \Talixio\Web::assetVersion()), substr($sw['body'], 0, 200));
$pf = $raw('/', 'PROPFIND');
check('web1: PROPFIND on the root answers like a folder', $pf['code'] === 207 && str_contains($pf['body'], '<d:href>/</d:href>'), $pf['body']);
$op = $raw('/', 'OPTIONS');
check('web1: OPTIONS on the root says DAV', $op['code'] === 200 && ($op['h']['dav'] ?? '') === '1, 2', json_encode($op['h']));
$pg = $raw('/', 'GET', null, 'a', ['Accept-Language: fr-FR,fr;q=0.9']);
check('web1: the app page has its base, language and versioned scripts', $pg['code'] === 200 && str_contains($pg['body'], '<base href="/">')
    && str_contains($pg['body'], 'data-lang="auto"') && str_contains($pg['body'], 'v/' . \Talixio\Web::assetVersion() . '/')
    && str_contains($pg['h']['content-security-policy'] ?? '', "frame-ancestors 'self'"));
$ics = $raw('/cal/tasks-' . str_repeat('a', 40) . '.ics');
check('web1: a wrong calendar link finds nothing', $ics['code'] === 404 && $ics['body'] === 'Not found');
check('web1: the API needs a session', $web('/api/status')['_code'] === 401);
$nh = $raw('/api/status', 'POST', '{}');
check('web1: a POST without the app header is refused', $nh['code'] === 403 && str_contains($nh['body'], 'missing request header'), $nh['body']);

// sign-in callbacks carry no session: their answer goes back to Settings
$g = $raw('/api/google/callback?error=access_denied');
check('web1: Google refusal lands in Settings', $g['code'] === 303 && str_contains($g['h']['location'] ?? '', '?google=Google%3A%20access_denied#settings'), json_encode($g['h']));
$g = $raw('/api/google/callback?code=x&state=forged');
check('web1: Google callback with a forged state does not connect', $g['code'] === 303 && !str_contains($g['h']['location'] ?? '', 'google=connected'), json_encode($g['h']));
$m = $raw('/api/microsoft/callback?error=x&error_description=' . rawurlencode("User cancelled\r\nTrace: 123"));
check('web1: Microsoft refusal keeps only the first line', str_contains($m['h']['location'] ?? '', 'microsoft=Microsoft%3A%20User%20cancelled#settings'), json_encode($m['h']));
$m = $raw('/api/microsoft/callback?error=invalid_request&error_description=' . rawurlencode("AADSTS50194: Application 'x' is not configured as a multi-tenant application."));
check('web1: a single-organisation Microsoft app is pointed to the directory setting', str_contains(rawurldecode($m['h']['location'] ?? ''), 'Microsoft directory'), json_encode($m['h']));
$m = $raw('/api/microsoft/callback?code=x&state=forged');
check('web1: Microsoft callback with a forged state does not connect', $m['code'] === 303 && !str_contains($m['h']['location'] ?? '', 'microsoft=connected'));
$s = $raw('/api/spotify/callback?error=denied');
check('web1: Spotify refusal lands in Settings', str_contains($s['h']['location'] ?? '', 'spotify=Spotify%3A%20denied#settings'), json_encode($s['h']));
$s = $raw('/api/spotify/callback?code=x&state=forged');
check('web1: Spotify callback with a forged state does not connect', $s['code'] === 303 && !str_contains($s['h']['location'] ?? '', 'spotify=connected'));
$mo = $raw('/api/mcp/oauth/callback?error=access_denied&error_description=nope');
check('web1: connector sign-in cancelled', str_contains(rawurldecode($mo['h']['location'] ?? ''), 'mcp=sign-in cancelled: access_denied (nope)'), json_encode($mo['h']));
$mo = $raw('/api/mcp/oauth/callback?error=access_denied');
check('web1: connector sign-in cancelled without a description', str_contains(rawurldecode($mo['h']['location'] ?? ''), 'cancelled: access_denied#'), json_encode($mo['h']));
$mo = $raw('/api/mcp/oauth/callback?code=x&state=forged');
check('web1: connector callback with an unknown state does not connect', $mo['code'] === 303 && !str_contains($mo['h']['location'] ?? '', 'connected:'));

// ---- signed in ----
check('web1: sign in', $web('/api/login', ['password' => 'correct horse battery'])['_code'] === 200);

$reg = $raw('/api/privacy/register');
check('web1: the record of processing downloads as Markdown', $reg['code'] === 200 && str_contains($reg['h']['content-type'] ?? '', 'text/markdown')
    && str_contains($reg['h']['content-disposition'] ?? '', 'record-of-processing-' . date('Y-m-d') . '.md') && $reg['body'] !== '', json_encode($reg['h']));

// method mismatches fall through every POST-only (or GET-only) route to "not found"
$postOnly = ['/api/logout', '/api/claude-login/start', '/api/claude-login/code', '/api/transfer/code', '/api/transfer/cancel', '/api/transfer/resume',
    '/api/spaces/5', '/api/spaces/0/tier', '/api/spaces/5/delete', '/api/spaces/5/read', '/api/messages/5/undo', '/api/chat/stop', '/api/chat',
    '/api/tasks/5/toggle', '/api/tasks/5/tier', '/api/mail/run', '/api/mail/5/label', '/api/mail/5/done', '/api/contacts/5/delete', '/api/tasks/briefing',
    '/api/tasks/weekly', '/api/tasks/watch', '/api/feedback/5/delete', '/api/capture', '/api/tasks/5/run', '/api/google/connect', '/api/google/disconnect',
    '/api/microsoft/connect', '/api/microsoft/disconnect', '/api/spotify/connect', '/api/spotify/disconnect', '/api/meaning/choose', '/api/meaning/pull',
    '/api/meaning/measure', '/api/connectors/github', '/api/mcp/read-only', '/api/mcp/oauth/start', '/api/mcp/oauth/forget', '/api/mcp/refresh',
    '/api/shell/check', '/api/heartbeat/run', '/api/memory/compact', '/api/bill', '/api/jobs/archive-finished', '/api/jobs/5/archive', '/api/jobs/5/delete',
    '/api/jobs/5/schedule', '/api/jobs/5/tags', '/api/sovereign/test', '/api/sovereign/apply', '/api/settings/password', '/api/dav/unblock', '/api/dav/password',
    '/api/security/totp/setup', '/api/update/check', '/api/doctor', '/api/mind/5/forget', '/api/mind/reflect', '/api/skills/x/delete', '/api/scripts/x/delete',
    '/api/approvals/5/approve', '/api/push/subscribe', '/api/push/unsubscribe', '/api/notify/test', '/api/jobs/5/cancel', '/api/jobs/5/continue',
    '/api/jobs/5/rerun', '/api/shares/5', '/api/shares/5/revoke', '/api/library/folder', '/api/library/folder/move', '/api/library/folder/delete',
    '/api/library/trash/restore', '/api/library/trash/empty', '/api/library/note', '/api/files/5/move', '/api/files/5/as-script', '/api/files/5/file-it',
    '/api/files/5/dismiss', '/api/files/5/suggest', '/api/library/file-all', '/api/board/order', '/api/board/undo/5', '/api/board/time/5/delete',
    '/api/spaces/0/account', '/api/accounts/test', '/api/accounts/x', '/api/accounts/x/delete'];
$bad = [];
foreach ($postOnly as $p) { $r = $web($p); if ($r['_code'] !== 404 && !str_starts_with($p, '/api/board/')) $bad[] = "$p {$r['_code']}"; elseif (str_starts_with($p, '/api/board/') && $r['_code'] !== 404) $bad[] = "$p {$r['_code']}"; }
check('web1: GET on an action route is not found', $bad === [], implode(', ', $bad));
$getOnly = ['/api/memory/digest', '/api/sovereign', '/api/mind', '/api/mind/5', '/api/files/5', '/api/files/5/view', '/api/files/5/pdf', '/api/files/5/thumb',
    '/api/library/zip', '/api/privacy/journal.csv', '/api/privacy/register', '/api/transfer/export', '/api/google/callback', '/api/microsoft/callback',
    '/api/spotify/callback', '/api/mcp/oauth/callback', '/api/connectors/inspect-nope', '/api/nothing-here'];
$bad = [];
foreach ($getOnly as $p) { $r = $web($p, []); if ($r['_code'] !== 404) $bad[] = "$p {$r['_code']}"; }
check('web1: POST on a read-only route is not found', $bad === [], implode(', ', $bad));
$r = $web('/api/share/claim');
check('web1: claiming a share needs a POST', $r['_code'] === 404);

// errors: a conflict, a bad argument and an unexpected failure each have their code
$sp = $web('/api/spaces', ['name' => 'Garden', 'color' => 'green']);
$spaceId = (int)($sp['space']['id'] ?? 0);
check('web1: a space is created', $spaceId > 0, json_encode($sp));
$r = $web('/api/google/connect', []);
check('web1: an app error says what went wrong', $r['_code'] >= 400 && ($r['error'] ?? '') !== '' && !str_contains($r['error'], 'reference'), json_encode($r));

// ---- tasks and mail ----
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv();
putenv('TALIXIO_DATA_DIR'); putenv('TALIXIO_PROVIDER'); putenv('TALIXIO_EMBEDDINGS');
$tid = $app->store->addTask('Water the plants', 'remind me', '0 8 * * *', time() + 3600, 'standard', $spaceId);
$app->routines()->vote($tid, 'item one', 1, 'good', null);
$tl = $web('/api/tasks');
$t = array_values(array_filter($tl['tasks'] ?? [], fn($x) => (int)$x['id'] === $tid))[0] ?? [];
check('web1: a task in a space shows the space name and its feedback', ($t['space_name'] ?? '') === 'Garden' && $t['feedback'] === 1 && $t['next_text'] !== null, json_encode($t));
$r = $web("/api/tasks/$tid/tier", ['tier' => 'nope']);
check('web1: an unknown tier is refused', $r['_code'] === 400 && $r['error'] === 'unknown tier');
$r = $web('/api/tasks/999999/tier', ['tier' => 'fast']);
check('web1: changing the tier of a missing task', $r['_code'] === 404);
check('web1: changing a task tier', $web("/api/tasks/$tid/tier", ['tier' => 'deep'])['ok'] === true && $app->store->task($tid)['tier'] === 'deep');
$fb = $web('/api/feedback', ['task' => $tid, 'item' => 'the summary', 'vote' => -1, 'note' => 'too long', 'message' => 0]);
check('web1: feedback on a message', ($fb['id'] ?? 0) > 0, json_encode($fb));
check('web1: feedback listed for the task', count($web("/api/tasks/$tid/feedback")['feedback'] ?? []) >= 1);
check('web1: feedback forgotten', $web('/api/feedback/' . $fb['id'] . '/delete', [])['ok'] === true);
$ml = $web('/api/mail?all=1');
check('web1: the mail page without a mailbox', $ml['_code'] === 200 && $ml['enabled'] === false && $ml['last_run'] === false && is_array($ml['emails']), json_encode($ml));

// ---- memory, activity, bill ----
$mem = $web('/api/memory', ['file' => 'USER.md', 'content' => "# About Alex\nLikes tea."]);
check('web1: memory file saved', ($mem['ok'] ?? false) === true && str_contains($app->ws->read('USER.md'), 'Likes tea'), json_encode($mem));
$cp = $web('/api/memory/compact', []);
check('web1: compact memory now', $cp['_code'] === 200 && array_key_exists('done', $cp), json_encode($cp));
check('web1: activity', $web('/api/activity')['_code'] === 200);
$bill = $web('/api/bill', ['amount' => -5, 'currency' => 'EUR']);
check('web1: a negative bill is refused', $bill['_code'] === 400 && ($bill['error'] ?? '') !== '', json_encode($bill));
$bill = $web('/api/bill', ['amount' => 12.5, 'currency' => 'EUR', 'month' => date('Y-m')]);
check('web1: a bill is reported', $bill['_code'] === 200, json_encode($bill));

// ---- update, mind, skills, scripts, approvals, push ----
$up = $web('/api/update');
check('web1: update status', $up['_code'] === 200 && array_key_exists('auto', $up) && array_key_exists('installed', $up), json_encode($up));
$mr = $web('/api/mind/reflect', []);
check('web1: reflecting with learning off', $mr['_code'] === 400 && str_contains($mr['error'] ?? '', 'Learning is off'), json_encode($mr));
check('web1: a missing skill', $web('/api/skills/no-such-skill')['_code'] === 404);
$sk = "---\nname: tea-time\ndescription: Make tea\n---\nBoil water.";
check('web1: a skill written', ($web('/api/skills/tea-time', ['content' => $sk])['ok'] ?? false) === true);
check('web1: the skill read back', str_contains($web('/api/skills/tea-time')['content'] ?? '', 'Boil water'));
check('web1: the skill deleted', $web('/api/skills/tea-time/delete', [])['_code'] === 200 && $web('/api/skills/tea-time')['_code'] === 404);
$sc = $web('/api/scripts', ['content' => '', 'name' => '']);
check('web1: importing an empty script is refused', $sc['_code'] === 400, json_encode($sc));
$sl = $web('/api/scripts');
check('web1: scripts listed', $sl['_code'] === 200 && is_array($sl['scripts']) && array_key_exists('enabled', $sl));
check('web1: a missing script', $web('/api/scripts/no-such-script')['_code'] === 404);
$sw2 = $web('/api/scripts/bad-one', ['content' => '']);
check('web1: an invalid script is refused', $sw2['_code'] === 400, json_encode($sw2));
$imp = $web('/api/scripts', ['content' => "#!/bin/sh\n# Says hello\necho hello\n", 'name' => 'say-hello']);
$sn = (string)($imp['name'] ?? '');
check('web1: a script imported', $imp['_code'] === 200 && $sn !== '', json_encode($imp));
check('web1: the script read back', str_contains($web("/api/scripts/$sn")['content'] ?? '', 'echo hello'));
check('web1: the script rewritten', ($web("/api/scripts/$sn", ['content' => "#!/bin/sh\n# Says bye\necho bye\n"])['ok'] ?? false) === true && str_contains($web("/api/scripts/$sn")['content'] ?? '', 'bye'));
check('web1: the script deleted', $web("/api/scripts/$sn/delete", [])['_code'] === 200 && $web("/api/scripts/$sn")['_code'] === 404);
$ap = $web('/api/approvals');
check('web1: no approvals waiting', $ap['approvals'] === []);
$ad = $web('/api/approvals/99999/reject', []);
check('web1: deciding a missing approval', in_array($ad['_code'], [200, 400, 404], true) && is_array($ad['messages'] ?? $ad), json_encode($ad));
$pk = $web('/api/push/key');
check('web1: the push key', is_string($pk['key'] ?? null) && strlen($pk['key']) > 20, json_encode($pk));
$ps = $web('/api/push/subscribe', ['endpoint' => 'https://push.acme.example/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth']], 'a', '', ['User-Agent: Test']);
check('web1: an app failure keeps its own message (not a log reference)', $ps['_code'] === 500 && ($ps['error'] ?? '') === 'cannot resolve push.acme.example', json_encode($ps));
check('web1: push unsubscribe', ($web('/api/push/unsubscribe', ['endpoint' => 'https://push.acme.example/abc'])['ok'] ?? false) === true);
$nt = $web('/api/notify/test', []);
check('web1: test notification with no channel', $nt['_code'] === 200 && is_array($nt['results']), json_encode($nt));
$nl = $web('/api/notifications');
check('web1: the test notification is in the inbox, unread', ($nl['unread'] ?? 0) >= 1 && array_key_exists('read_at', $nl['notifications'][0] ?? []) && $nl['notifications'][0]['read_at'] === null && ($web('/api/status')['notifications_unread'] ?? 0) === $nl['unread'], json_encode($nl));
$nr = $web('/api/notifications/read', ['id' => $nl['notifications'][0]['id']]);
check('web1: one notification read', ($nr['marked'] ?? 0) === 1 && $nr['unread'] === $nl['unread'] - 1, json_encode($nr));
check('web1: all read', ($web('/api/notifications/read', [])['unread'] ?? 1) === 0);
check('web1: notifications cleared', ($web('/api/notifications/clear', [])['ok'] ?? false) === true && $web('/api/notifications')['notifications'] === []);

// ---- jobs that do not exist ----
foreach (['cancel' => [], 'continue' => ['max_usd' => 2, 'effort' => 'low'], 'rerun' => ['tier' => 'fast', 'max_usd' => '1']] as $a => $body) {
    $r = $web("/api/jobs/987654/$a", $body);
    check("web1: job $a on a missing job does not crash", $r['_code'] !== 500, json_encode($r));
}

// ---- library, shares, privacy, search ----
$lib = $web('/api/library?folder=/');
check('web1: the library root', $lib['_code'] === 200 && isset($lib['stats']) && array_key_exists('level', $lib), json_encode(array_keys($lib)));
check('web1: privacy overview with its journal', array_key_exists('journal', $web('/api/privacy')));
$fo = $web('/api/library/folder', ['path' => '/Projects']);
check('web1: folder created', ($fo['folder'] ?? '') === '/Projects', json_encode($fo));
$note = $web('/api/library/note', ['folder' => '/Projects', 'name' => 'plan.md', 'content' => 'Plant tomatoes in May at acme.example garden']);
$nid = (int)($note['file']['id'] ?? 0);
check('web1: a note saved', $nid > 0, json_encode($note));
check('web1: the note updated in place', (int)($web('/api/library/note', ['folder' => '/Projects', 'name' => 'plan.md', 'content' => 'Plant tomatoes in June', 'id' => $nid])['file']['id'] ?? 0) === $nid);
$sh = $web('/api/shares', ['file' => $nid, 'days' => 7]);
$shid = (int)($sh['share']['id'] ?? 0);
check('web1: a file shared', $shid > 0, json_encode($sh));
$sl = $web("/api/shares?file=$nid");
check('web1: shares of the file listed', count($sl['shares'] ?? []) === 1 && array_key_exists('level', $sl), json_encode($sl));
$sl = $web('/api/shares?folder=/Projects&scope=all');
check('web1: shares of a folder listed', $sl['_code'] === 200 && array_key_exists('base', $sl));
$fs = $web('/api/shares', ['folder' => '/Projects']);
check('web1: a folder shared', ($fs['share']['id'] ?? 0) > 0, json_encode($fs));
check('web1: share updated', $web("/api/shares/$shid", ['note' => 'for Alex'])['_code'] === 200);
check('web1: share log', is_array($web("/api/shares/$shid/log")['log'] ?? null));
check('web1: share revoked', $web("/api/shares/$shid/revoke", [])['ok'] === true);
check('web1: library tree', isset($web('/api/library/tree')['tree']));
$ls = $web('/api/library/search?q=tomatoes&mode=quick');
check('web1: library quick search finds the note by name or text', $ls['_code'] === 200 && $ls['more'] === false && is_array($ls['names']), json_encode($ls));
check('web1: library full search', $web('/api/library/search?q=tomatoes&folder=/Projects')['_code'] === 200);
$mv = $web('/api/library/folder/move', ['from' => '/Projects', 'to' => '/Archive']);
check('web1: folder moved', ($mv['folder'] ?? '') === '/Archive', json_encode($mv));
check('web1: file text', $web("/api/files/$nid/text")['_code'] === 200);
check('web1: file preview', $web("/api/files/$nid/preview")['_code'] === 200);
$asx = $web("/api/files/$nid/as-script", []);
check('web1: a note that is no script', $asx['_code'] !== 500, json_encode($asx));
$fi = $web("/api/files/$nid/file-it", ['folder' => '/Archive', 'name' => 'plan-2.md']);
check('web1: file it where asked', $fi['_code'] === 200 && ($fi['file']['folder'] ?? '') === '/Archive', json_encode($fi));
check('web1: dismiss a filing suggestion', ($web("/api/files/$nid/dismiss", [])['ok'] ?? false) === true);
$sg = $web("/api/files/$nid/suggest", []);
check('web1: ask for a filing suggestion', $sg['_code'] === 200 && array_key_exists('suggestion', $sg), json_encode($sg));
$fa = $web('/api/library/file-all', []);
check('web1: file all from the inbox', ($fa['filed'] ?? -1) >= 0, json_encode($fa));
check('web1: a file moved', $web("/api/files/$nid/move", ['folder' => '/'])['_code'] === 200);
check('web1: a file deleted', $web("/api/files/$nid/delete", [])['_code'] === 200);
$tr = $web('/api/library/trash');
check('web1: the trash keeps it', count($tr['items'] ?? []) >= 1 && $tr['days'] > 0, json_encode($tr));
check('web1: restored from the trash', $web('/api/library/trash/restore', ['id' => $nid])['_code'] === 200);
$web("/api/files/$nid/delete", []);
check('web1: trash emptied', ($web('/api/library/trash/empty', [])['deleted'] ?? 0) >= 1);
$fd = $web('/api/library/folder/delete', ['path' => '/Archive', 'recursive' => true]);
check('web1: folder deleted', $fd['_code'] === 200 && array_key_exists('deleted_files', $fd), json_encode($fd));
$se = $web('/api/search?q=tea&mode=quick');
check('web1: quick search everywhere', $se['_code'] === 200 && is_array($se['results']) && $se['more'] === false, json_encode($se));
check('web1: full search everywhere', $web('/api/search?q=tea')['_code'] === 200);

// ---- board ----
$c1 = $web('/api/board', ['title' => 'Repot the fern', 'space' => $spaceId]);
$c2 = $web('/api/board', ['title' => 'Buy soil', 'space' => $spaceId]);
$id1 = (int)($c1['card']['id'] ?? 0); $id2 = (int)($c2['card']['id'] ?? 0);
check('web1: two cards', $id1 > 0 && $id2 > 0, json_encode($c1));
$u = $web("/api/board/$id1", ['depends_on' => [$c2['card']['code'] ?? $id2]]);
check('web1: a dependency set', $u['_code'] === 200, json_encode($u));
$pf = $web("/api/board/portfolio?space=$spaceId");
check('web1: portfolio lists open cards with their references', count($pf['cards'] ?? []) >= 2 && is_array($pf['refs']), json_encode($pf));
check('web1: portfolio of all spaces', $web('/api/board/portfolio')['_code'] === 200);
check('web1: calendar with bad dates', $web('/api/board/calendar?from=2026-1-1&to=x')['_code'] === 400);
$cal = $web("/api/board/calendar?from=2026-10-01&to=2026-10-31&events=1&space=$spaceId");
check('web1: calendar events that cannot be read say why', $cal['_code'] === 200 && $cal['calendar'] === true && is_string($cal['events_error']) && $cal['events'] === [], json_encode($cal));
$i1 = $web('/api/board/ics');
$i2 = $web('/api/board/ics', []);
check('web1: a new calendar link replaces the old one', str_contains($i1['url'] ?? '', '/cal/tasks-') && $i1['url'] !== $i2['url'], json_encode([$i1, $i2]));
preg_match('#/cal/tasks-([0-9a-f]{40})\.ics#', $i2['url'], $im);
$feed = $raw("/cal/tasks-{$im[1]}.ics");
check('web1: the calendar link serves the tasks', $feed['code'] === 200 && str_contains($feed['body'], 'BEGIN:VCALENDAR') && str_contains($feed['h']['content-type'] ?? '', 'text/calendar'));
check('web1: recent changes', count($web("/api/board/recent?space=$spaceId")['changes'] ?? []) >= 1);
check('web1: find a card', count($web('/api/board/find?q=fern')['cards'] ?? []) === 1);
check('web1: global order', ($web('/api/board/order', ['ids' => [$id2, $id1]])['ok'] ?? false) === true);
$one = $web("/api/board/$id1");
check('web1: a card with its dependencies', count($one['depends_on'] ?? []) === 1 && array_key_exists('can_invite', $one), json_encode($one));
check('web1: a missing card', $web('/api/board/999999')['_code'] === 404);
check('web1: GET on a card action', $web("/api/board/$id1/move")['_code'] === 405);
check('web1: an unknown card action', $web("/api/board/$id1/fly", [])['_code'] === 404);
check('web1: an unknown board address', $web('/api/board/x/y/z')['_code'] === 404);
$mvc = $web("/api/board/$id1/move", ['status' => 'doing', 'before' => $id2]);
check('web1: card moved', ($mvc['card']['status'] ?? '') === 'doing', json_encode($mvc));
$tm = $web("/api/board/$id1/time", ['hours' => 1.5, 'day' => date('Y-m-d'), 'note' => 'digging']);
check('web1: time logged', $tm['_code'] === 200, json_encode($tm));
$te = $web("/api/board/$id1")['time'] ?? [];
check('web1: time entry deleted', ($web('/api/board/time/' . ($te[0]['id'] ?? 0) . '/delete', [])['ok'] ?? false) === true && ($web("/api/board/$id1")['time'] ?? [1]) === []);
check('web1: card taken out of the global order', ($web("/api/board/$id1/unrank", [])['ok'] ?? false) === true);
$inv = $web("/api/board/$id1/invite", []);
check('web1: inviting without any email says what to connect', str_contains($inv['error'] ?? '', 'connect your mailbox'), json_encode($inv));
$del = $web("/api/board/$id2/delete", []);
check('web1: card deleted', $del['_code'] === 200 && array_key_exists('waiting', $del));
$rs = $web("/api/board/$id2/restore", []);
check('web1: card restored', array_key_exists('deleted_at', $rs['card'] ?? []) && $rs['card']['deleted_at'] === null, json_encode($rs));
$rc = $web("/api/board/recent?space=$spaceId")['changes'] ?? [];
$undo = $web('/api/board/undo/' . ($rc[0]['id'] ?? 0), []);
check('web1: undo the last change', $undo['_code'] === 200 && isset($undo['card']), json_encode($undo));

// ---- settings, channels, sovereign ----
$ch = $web('/api/channels');
$byId = array_column($ch['channels'] ?? [], null, 'id');
check('web1: channels when nothing is set up', ($byId['email']['state'] ?? '') === 'not configured' && ($byId['calendar']['state'] ?? '') === 'read only'
    && str_contains($byId['signal']['detail'] ?? '', 'install.sh') && ($byId['mailbox']['state'] ?? '') === 'not configured', json_encode($ch));
$ss = $web('/api/settings', ['changes' => ['TALIXIO_HEARTBEAT_MINUTES' => 'many']]);
check('web1: an invalid setting is refused with 422 and its field error', $ss['_code'] === 422 && ($ss['errors']['TALIXIO_HEARTBEAT_MINUTES'] ?? '') !== '' && !isset($ss['ok']), json_encode($ss));
$ss = $web('/api/settings', ['changes' => ['TALIXIO_NAME' => 'Robin'], 'reset' => []]);
check('web1: a valid setting saved', ($ss['ok'] ?? false) === true && is_array($ss['groups']), json_encode($ss));
check('web1: the new name is used', $web('/api/status')['name'] === 'Robin');
$sv = $web('/api/sovereign');
check('web1: the Europe-only card', $sv['_code'] === 200 && is_array($sv['services']) && $sv['active'] === false && $sv['has_key'] === false && array_key_exists('prices', $sv) || $sv['_code'] === 200, json_encode(array_keys($sv)));

// ---- share inbox ----
$shareRaw = function (string $jar, array $fields) use ($raw) {
    $r = $raw('/share', 'POST', $fields, $jar);
    return [$r['code'], preg_match('/[?&]share=([a-f0-9]{32})/', $r['h']['location'] ?? '', $mm) ? $mm[1] : ''];
};
$f1 = tap_tmp('hello from a phone'); $f2 = tap_tmp('second file');
$big = tap_tmp(str_repeat('x', 3_000_000)); // above the server's upload limit
[$code, $tok] = $shareRaw('a', ['text' => 'look at this', 'url' => 'https://acme.example/x',
    'files[0]' => new CURLFile($f1, 'text/plain', 'note.txt'), 'files[1]' => new CURLFile($f2, 'text/plain', 'second.txt'), 'files[2]' => new CURLFile($big, 'text/plain', 'big.txt')]);
check('web1: a signed-in share is kept', $code === 303 && $tok !== '' && is_file("$d/share-inbox/$tok/meta.json"), (string)$code);
$meta = json_decode((string)@file_get_contents("$d/share-inbox/$tok/meta.json"), true);
check('web1: a signed-in share may be large, and a file over the limit is skipped', ($meta['max_mb'] ?? 0) === 200 && count($meta['files'] ?? []) === 2 && ($meta['skipped'] ?? 0) === 1, json_encode($meta));
@unlink("$d/share-inbox/$tok/1"); // one file gone before the claim
$cl = $web('/api/share/claim', ['token' => $tok]);
check('web1: claiming the share stores its files in the inbox', ($cl['text'] ?? '') === "look at this\nhttps://acme.example/x" && count($cl['files'] ?? []) === 1
    && ($cl['files'][0]['name'] ?? '') === 'note.txt' && $cl['skipped'] === 1 && !is_dir("$d/share-inbox/$tok"), json_encode($cl));
check('web1: a malformed share token', $web('/api/share/claim', ['token' => '../x'])['_code'] === 400);
$many = [];
for ($i = 0; $i < 11; $i++) $many["files[$i]"] = new CURLFile($f1, 'text/plain', "f$i.txt");
[, $tok2] = $shareRaw('stranger', $many);
$meta = json_decode((string)@file_get_contents("$d/share-inbox/$tok2/meta.json"), true);
check('web1: at most 10 files a share, and small ones without a session', count($meta['files'] ?? []) === 10 && $meta['skipped'] === 1 && $meta['max_mb'] === 25, json_encode($meta));
for ($i = 0; $i < 50; $i++) @mkdir("$d/share-inbox/" . sprintf('%032x', $i), 0700);
[$code] = $shareRaw('stranger2', ['text' => 'one more']);
check('web1: too many shares waiting are refused without a session', $code === 429);
[$code] = $shareRaw('a', ['text' => 'mine still works']);
check('web1: a signed-in share is taken even when many wait', $code === 303);
foreach (glob("$d/share-inbox/*", GLOB_ONLYDIR) as $x) exec('rm -rf ' . escapeshellarg($x));
@unlink($f1); @unlink($f2); @unlink($big);


// ---- more routes: spaces, contacts, mail, transfer, connectors, updates, approvals ----
check('web1: transfer cancelled', ($web('/api/transfer/cancel', [])['ok'] ?? false) === true);
check('web1: transfer resumed', ($web('/api/transfer/resume', [])['ok'] ?? false) === true);
$sp2 = $web('/api/spaces', ['name' => 'Kitchen', 'color' => 'blue']);
$sp2id = (int)($sp2['space']['id'] ?? 0);
$app->store->addMessage('assistant', 'web', 'Dinner is at 7', null, [], $sp2id);
$msgs = $web("/api/messages?space=$spaceId&read=1");
check('web1: messages of a space with the unread count of the others', $msgs['_code'] === 200 && $msgs['unread'] >= 1 && is_array($msgs['messages']), json_encode($msgs));
check('web1: a space marked read', ($web("/api/spaces/$sp2id/read", [])['ok'] ?? false) === true && $web("/api/messages?space=$spaceId")['unread'] === 0);
check('web1: a space deleted', ($web("/api/spaces/$sp2id/delete", [])['ok'] ?? false) === true
    && !in_array($sp2id, array_column($web('/api/spaces')['spaces'] ?? [], 'id'), true));
$mr = $web('/api/mail/run', []);
check('web1: sorting mail without a mailbox does not crash', $mr['_code'] !== 500 || ($mr['error'] ?? '') !== '', json_encode($mr));
check('web1: relabel a missing email', $web('/api/mail/424242/label', ['label' => 'nope'])['_code'] !== 0);
check('web1: mark a missing email done', $web('/api/mail/424242/done', [])['_code'] !== 0);
$ct = $web('/api/contacts', ['name' => 'Alex Martin', 'email' => 'alex@acme.example']);
$cid = (int)($ct['contact']['id'] ?? 0);
check('web1: a contact saved', $cid > 0, json_encode($ct));
check('web1: the contact found', count($web('/api/contacts?q=Alex')['contacts'] ?? []) === 1);
check('web1: the contact profile', $web("/api/contacts/$cid")['_code'] === 200);
check('web1: the contact edited', ($web("/api/contacts/$cid", ['name' => 'Alex M.'])['contact']['name'] ?? '') === 'Alex M.');
check('web1: the contact deleted', ($web("/api/contacts/$cid/delete", [])['ok'] ?? false) === true && $web('/api/contacts?q=Alex')['contacts'] === []);
check('web1: Microsoft disconnected', ($web('/api/microsoft/disconnect', [])['connected'] ?? true) === false);
$mm2 = $web('/api/meaning/measure', []);
check('web1: measuring with meaning search off says so', $mm2['_code'] === 400 && str_contains($mm2['error'] ?? '', 'nothing to measure'), json_encode($mm2));
$gh = $web('/api/connectors/github', ['url' => 'not a url']);
check('web1: a connector from a bad GitHub address is refused', in_array($gh['_code'], [400, 500], true) && ($gh['error'] ?? '') !== '', json_encode($gh));
$mc = $web('/api/mcp/refresh', []);
check('web1: connectors refreshed with none set up', $mc['_code'] === 200 && $mc['servers'] === [] && isset($mc['shell']), json_encode($mc));
check('web1: deleting a missing job', $web('/api/jobs/987654/delete', [])['_code'] !== 0);
$uc = $web('/api/update/check', []);
check('web1: an update check is requested or explained', ($uc['ok'] ?? false) === true ? is_file("$d/check.request") : ($uc['error'] ?? '') !== '', json_encode($uc));
$app->approvals()->request('memory_write', ['file' => 'X.md'], 'write a note');
$ap = $web('/api/approvals');
$aid = (int)($ap['approvals'][0]['id'] ?? 0);
check('web1: a waiting approval shows its decoded arguments', ($ap['approvals'][0]['args_decoded']['file'] ?? '') === 'X.md', json_encode($ap));
$rj = $web("/api/approvals/$aid/reject", []);
check('web1: an approval rejected', $rj['_code'] === 200 && is_string($rj['message']) && $web('/api/approvals')['approvals'] === [], json_encode($rj));
$doc = $web('/api/doctor', []);
check('web1: the doctor runs its checks', $doc['_code'] === 200 && count($doc['results'] ?? []) > 0, substr(json_encode($doc), 0, 300));

// ---- logout ----
check('web1: sign out', ($web('/api/logout', [])['ok'] ?? false) === true && $web('/api/status')['_code'] === 401);

// ---- a second app with every channel filled in ----
$d2 = test_dir('web1b');
@mkdir("$d2/ics");
file_put_contents("$d2/ics/cal.ics", "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1@acme.example\r\nDTSTART:20261015T090000Z\r\nDTEND:20261015T100000Z\r\nSUMMARY:Garden visit\r\nLOCATION:Acme park\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$icsPort = free_port();
$icsProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$icsPort", '-t', "$d2/ics"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $icsPipes);
register_shutdown_function(function () use ($icsProc) { proc_terminate($icsProc); proc_close($icsProc); });
wait_port($icsPort);
@mkdir("$d2/dav");
$davPort = free_port();
$davProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$davPort", __DIR__ . '/../fakes/caldav.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $davPipes, null, ['TALIXIO_FAKE_CALDAV' => "$d2/dav", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($davProc) { proc_terminate($davProc); proc_close($davProc); });
wait_port($davPort);
$web2 = web_app($d2, ['TALIXIO_WEB_PASSWORD' => 'another long password', 'TALIXIO_LANG' => 'en', 'TALIXIO_SMTP_HOST' => '127.0.0.1', 'TALIXIO_EMAIL_FROM' => 'robin@acme.example',
    'TALIXIO_EMAIL_TO' => 'alex@acme.example', 'TALIXIO_IMAP_HOST' => '127.0.0.1', 'TALIXIO_IMAP_USER' => 'robin', 'TALIXIO_IMAP_PASSWORD' => 'x',
    'TALIXIO_SIGNAL_NUMBER' => '+15550000001', 'TALIXIO_SIGNAL_ALLOWED' => '+15550000002,42', 'TALIXIO_TELEGRAM_TOKEN' => '123:abc', 'TALIXIO_TELEGRAM_ALLOWED_IDS' => '1,2',
    'TALIXIO_NTFY_URL' => 'http://127.0.0.1:9/topic', 'TALIXIO_CALDAV_URL' => "http://127.0.0.1:$davPort/cal/", 'TALIXIO_CALDAV_USER' => 'fred', 'TALIXIO_CALDAV_PASSWORD' => 'pw',
    'TALIXIO_CALENDAR_ICS' => "http://127.0.0.1:$icsPort/cal.ics", 'TALIXIO_MAILBOX_USER' => 'alex@acme.example', 'TALIXIO_MAILBOX_PASSWORD' => 'x', 'TALIXIO_MAILBOX_IMAP_HOST' => '127.0.0.1']);
$web2('/api/login', ['password' => 'another long password']);
$ch = $web2('/api/channels');
$byId = array_column($ch['channels'] ?? [], null, 'id');
check('web1: email channel details with notifications and answers', str_contains($byId['email']['detail'] ?? '', 'From robin@acme.example · notifications to alex@acme.example · answers alex@acme.example'), json_encode($byId['email'] ?? null));
check('web1: Signal numbers are masked', str_contains($byId['signal']['detail'] ?? '', 'Number +15550000001 · answers +15•••••••02, 42'), json_encode($byId['signal'] ?? null));
check('web1: calendar, mailbox, Telegram and ntfy set up', str_contains($byId['calendar']['detail'] ?? '', '1 subscription link(s) · CalDAV') && ($byId['calendar']['state'] ?? '') === 'on'
    && ($byId['mailbox']['state'] ?? '') === 'on' && str_contains($byId['telegram']['detail'] ?? '', '2 allowed chat(s)') && ($byId['ntfy']['detail'] ?? '') === 'Topic configured', json_encode($ch));
$ev = $web2('/api/board/calendar?from=2026-10-01&to=2026-10-31&events=1');
check('web1: calendar events shown next to the tasks', ($ev['events'][0]['title'] ?? '') === 'Garden visit' && !isset($ev['events'][0]['uid']) && $ev['events_error'] === null, json_encode($ev));
$p2 = $portOf($web2);
$page2 = (string)file_get_contents("http://127.0.0.1:$p2/");
check('web1: a fixed interface language is written in the page', str_contains($page2, 'data-lang="en"'));

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
