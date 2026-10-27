<?php
declare(strict_types=1);
// The web API's back half (src/Web.php from sovereign() on), the network drive (src/Dav.php) and links for people outside (src/Shares.php).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Auth, Web, Files, Skills};

$d = test_dir('web2');
$dw = "$d/w"; @mkdir($dw, 0777, true);
$pw = 'a-long-password-123';
$web = web_app($dw, ['TALIXIO_WEB_PASSWORD' => $pw]);
$port = (int)(new ReflectionFunction($web))->getStaticVariables()['port'];

// raw requests (uploads, pages, headers, ranges): returns code, body, lower-cased headers
$w2raw = function (string $path, string $method = 'GET', $fields = null, array $headers = [], string $jar = 'a', ?int $p = null, string $dir = '') use ($port, $dw) {
    $dir = $dir ?: $dw;
    $ch = curl_init('http://127.0.0.1:' . ($p ?? $port) . $path);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => "$dir/ck-$jar", CURLOPT_COOKIEFILE => "$dir/ck-$jar",
        CURLOPT_TIMEOUT => 60, CURLOPT_PATH_AS_IS => true, CURLOPT_HTTPHEADER => array_merge(['X-Talixio: 1', 'X-Forwarded-For: 203.0.113.7'], $headers),
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($h); }]
        + ($method === 'HEAD' ? [CURLOPT_NOBODY => true] : [])
        + ($fields !== null ? [CURLOPT_POSTFIELDS => $fields] : []));
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'h' => $hdr];
};

// an App on the same data directory, for setting things up and looking inside
$w2app = function (string $dir, array $env = []) {
    $env += ['TALIXIO_DATA_DIR' => $dir, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none'];
    $old = [];
    foreach ($env as $k => $v) { $old[$k] = getenv($k); putenv("$k=$v"); }
    try { return App::fromEnv(); }
    finally { foreach ($old as $k => $v) putenv($v === false ? $k : "$k=$v"); }
};
$app = $w2app($dw);
$save = function (string $name, string $content, string $folder = '/Inbox', string $mime = '') use ($app): int {
    $t = tempnam(sys_get_temp_dir(), 'w2'); file_put_contents($t, $content);
    return $app->files()->save($t, $name, $mime, $folder);
};
$priv = fn(string $m, ...$a) => (new ReflectionMethod(Web::class, $m))->invoke(null, ...$a);

check('web2: signing in works', ($web('/api/login', ['password' => $pw])['ok'] ?? false) === true);

// ---- Pilotage slice 2: the Rôles catalogue aggregate (/api/roles) — runs before the password tests rotate the session ----
$app->skills()->save('triage-x', 'Sort incoming mail into the right next action', 'Read the mail and decide what happens next.',
    ['kind' => 'role', 'tier' => 'standard', 'tools' => 'mailbox_list', 'version' => '2']);
$app->assessor()->addCase('triage-x', 'An invoice arrives', '', 'files it and flags the due date');
$app->assessor()->record('triage-x', true, 0.01);
$app->assessor()->record('triage-x', false, 0.02);
$app->assessor()->record('triage-x', true, 0.015);
$rc = $web('/api/roles');
$byName = array_column($rc['roles'] ?? [], null, 'name');
$tx = $byName['triage-x'] ?? [];
check('web2 roles: one aggregate joins the header with the Assessor numbers, no per-role round-trips',
    $rc['_code'] === 200 && ($tx['runs'] ?? 0) === 3 && abs(($tx['acceptance'] ?? 0) - 0.67) < 0.01 && ($tx['cases'] ?? 0) === 1
    && ($tx['tier'] ?? '') === 'standard' && ($tx['version'] ?? 0) === 2 && ($tx['protected'] ?? true) === false
    && ($tx['published'] ?? true) === false && in_array('acceptance+cost', $tx['metrics'] ?? [], true) && is_array($tx['series'] ?? null),
    substr($rc['_raw'], 0, 500));
check('web2 roles: role #0 (the assessor) is in the catalogue and flagged protected',
    isset($byName['assessor']) && ($byName['assessor']['protected'] ?? false) === true, substr($rc['_raw'], 0, 300));
$ev = $web('/api/roles/triage-x/evaluate', []);
check('web2 roles: Evaluate wires to the assessor read, naming the role and its runs',
    $ev['_code'] === 200 && str_contains((string)($ev['message'] ?? ''), 'triage-x') && str_contains((string)($ev['message'] ?? ''), 'run'),
    substr($ev['_raw'], 0, 300));

// ---- Pilotage slice 3: specialization grouping + the overlap/doublon signal ----
// A near-twin of triage-x so the lexical overlap pass has a pair to surface.
$app->skills()->save('triage-y', 'Sort incoming mail into the right next action', 'Read the mail and route it to the next action.',
    ['kind' => 'role', 'tier' => 'standard', 'tools' => 'mailbox_list']);
$ov = $app->skills()->overlaps(0.6);
check('web2 roles: overlaps() surfaces the twin as a close pair, scored and ordered',
    is_array($ov) && (bool)array_filter($ov, fn($p) => ($p['score'] ?? 0) >= 0.6
        && (($p['a'] === 'triage-x' && $p['b'] === 'triage-y') || ($p['a'] === 'triage-y' && $p['b'] === 'triage-x'))),
    json_encode($ov));
$rc2 = $web('/api/roles');
check('web2 roles: /api/roles carries the overlaps alongside the catalogue, no second call',
    $rc2['_code'] === 200 && is_array($rc2['overlaps'] ?? null)
    && (bool)array_filter($rc2['overlaps'], fn($p) => in_array('triage-x', [$p['a'] ?? '', $p['b'] ?? ''], true) && in_array('triage-y', [$p['a'] ?? '', $p['b'] ?? ''], true)),
    substr($rc2['_raw'], 0, 400));
$sp = $web('/api/roles/triage-x/specialization', ['specialization' => 'Inbox']);
check('web2 roles: setting a specialization is an ok, and it lands in the header and the catalogue',
    ($sp['ok'] ?? false) === true && ($app->skills()->meta('triage-x')['specialization'] ?? '') === 'Inbox'
    && (($app->skills()->meta('triage-x')['version'] ?? '') === '2')  // the rest of the header survives the one-field write
    && (array_column($web('/api/roles')['roles'] ?? [], 'specialization', 'name')['triage-x'] ?? '') === 'Inbox',
    substr($sp['_raw'], 0, 300));
$sp = $web('/api/roles/triage-x/specialization', ['specialization' => '']);
check('web2 roles: clearing a specialization drops the field',
    ($sp['ok'] ?? false) === true && !array_key_exists('specialization', $app->skills()->meta('triage-x')));
$sp = $web('/api/roles/not-a-role/specialization', ['specialization' => 'x']);
check('web2 roles: a specialization write to a non-role is a 404', $sp['_code'] === 404);

// ---- Pilotage slice 4: role lifecycle — archive, delete-guard, improve ----
$ar = $web('/api/roles/triage-y/archive', ['on' => true]);
$rc3 = $web('/api/roles');
check('web2 roles: archiving drops the role from the map, the overlap pass and the director’s reach, but keeps it under Archived',
    ($ar['ok'] ?? false) === true
    && !in_array('triage-y', array_column($rc3['roles'] ?? [], 'name'), true)
    && in_array('triage-y', array_column($rc3['archived'] ?? [], 'name'), true)
    && !array_filter($rc3['overlaps'] ?? [], fn($p) => in_array('triage-y', [$p['a'] ?? '', $p['b'] ?? ''], true))
    && !in_array('triage-y', array_column($app->skills()->all(), 'name'), true)           // default list hides it
    && in_array('triage-y', array_column($app->skills()->all(true), 'name'), true)          // the file is still there
    && Skills::isArchived($app->skills()->meta('triage-y')),
    substr($rc3['_raw'], 0, 400));
check('web2 roles: an archived role is out of the director’s role_find', !in_array('triage-y', array_column($app->skills()->find('incoming mail next action', 5, 'role'), 'name'), true));
$re = $web('/api/roles/triage-y/archive', ['on' => false]);
check('web2 roles: reactivating brings it back into the map',
    ($re['ok'] ?? false) === true && in_array('triage-y', array_column($web('/api/roles')['roles'] ?? [], 'name'), true)
    && !Skills::isArchived($app->skills()->meta('triage-y')));
$dx = $web('/api/roles/triage-x/delete', []);
check('web2 roles: deleting a role that has history is refused (409), steering to archive', $dx['_code'] === 409 && $app->skills()->meta('triage-x') !== [], substr($dx['_raw'], 0, 200));
$app->skills()->save('triage-z', 'A role that never ran', 'Do nothing yet.', ['kind' => 'role']);
$dz = $web('/api/roles/triage-z/delete', []);
check('web2 roles: a role that never ran deletes cleanly', ($dz['ok'] ?? false) === true && $app->skills()->meta('triage-z') === []);
$pa = $web('/api/roles/assessor/archive', ['on' => true]);
$pd = $web('/api/roles/assessor/delete', []);
check('web2 roles: the protected assessor can be neither archived nor deleted (403)',
    $pa['_code'] === 403 && $pd['_code'] === 403 && !Skills::isArchived($app->skills()->meta('assessor')));
$im = $web('/api/roles/triage-x/improve', ['content' => 'Read the mail, decide the single next action, and state it in one line.']);
check('web2 roles: Improve hands the candidate to the Assessor’s A/B job (triage-x has a golden case)',
    $im['_code'] === 200 && str_contains((string)($im['message'] ?? ''), 'evaluation job'), substr($im['_raw'], 0, 300));
$im = $web('/api/roles/triage-y/improve', ['content' => 'anything']);
check('web2 roles: Improve on a role with no golden cases explains why, promoting nothing',
    $im['_code'] === 200 && str_contains((string)($im['message'] ?? ''), 'golden case'), substr($im['_raw'], 0, 300));

// ---- Pilotage slice 5: the KB glance aggregate (/api/library/kb) ----
$app->library()->createFolder('/Dossier');
$app->library()->saveNote('/Dossier', 'kb-note', 'A grounding note for the glance.');
$kb = $web('/api/library/kb');
check('web2 kb: the glance reports the space root, a recursive file count under it, a subfolder, the library size and the hub pull grant',
    $kb['_code'] === 200 && $kb['root'] === '/' && (int)($kb['files'] ?? 0) >= 1 && (int)($kb['subfolders'] ?? 0) >= 1
    && isset($kb['library']['bytes']) && ($kb['hub']['pull'] ?? null) === false, substr($kb['_raw'], 0, 300));

// ---- the Europe-only card ----
$sv = $web('/api/sovereign');
check('web2 sovereign: lists the providers with their prices, not active', $sv['_code'] === 200 && in_array('mistral', array_column($sv['services'], 'id'), true) && $sv['active'] === false
    && $sv['tiers'] === [] && array_key_exists('mistral-small-latest', $sv['prices'] ?? []), substr($sv['_raw'], 0, 300));
$st = $web('/api/sovereign/test', ['service' => 'nope']);
check('web2 sovereign: an unknown provider is a 400', $st['_code'] === 400 && $st['error'] === 'Unknown provider.');
$st = $web('/api/sovereign/test', ['service' => 'mistral', 'key' => '', 'url' => 'https://127.0.0.1:1/v1']);
check('web2 sovereign: without a key the test asks for one', $st['_code'] === 400 && $st['error'] === 'Paste your API key.');
$sa = $web('/api/sovereign/apply', ['service' => 'mistral', 'key' => 'k', 'url' => 'http://insecure.example']);
check('web2 sovereign: apply refuses a plain http address and saves nothing', $sa['_code'] === 400 && str_contains($sa['error'], 'https://'));

// ---- password and two-step sign-in ----
$r = $web('/api/settings/password', ['current' => 'wrong-one', 'new' => 'whatever-long-enough']);
check('web2 password: a wrong current password is a 403', $r['_code'] === 403 && $r['error'] === 'The current password is wrong.');
$r = $web('/api/settings/password', ['current' => $pw, 'new' => 'short']);
check('web2 password: a new password under 12 characters is refused', $r['_code'] === 400 && str_contains($r['error'], '12 characters'));
$pw2 = 'another-long-password-456';
$r = $web('/api/settings/password', ['current' => $pw, 'new' => $pw2]);
check('web2 password: changed, this device stays signed in', ($r['ok'] ?? false) === true && $web('/api/security')['_code'] === 200, $r['_raw']);
check('web2 password: the old password no longer signs in', $web('/api/login', ['password' => $pw], 'old')['_code'] === 401);
$pw = $pw2;
check('web2 security: two-step sign-in is off', $web('/api/security')['totp'] === false);
$r = $web('/api/security/totp/enable', ['password' => $pw, 'code' => '000000']);
check('web2 totp: enable before setup asks to start again', $r['_code'] === 400 && $r['error'] === 'Start the setup again.');
$r = $web('/api/security/totp/setup', []);
$secret = (string)($r['secret'] ?? '');
check('web2 totp: setup gives a secret and an otpauth address', strlen($secret) >= 16 && str_starts_with((string)($r['uri'] ?? ''), 'otpauth://totp/'), $r['_raw']);
$r = $web('/api/security/totp/enable', ['password' => $pw, 'code' => '12']);
check('web2 totp: a wrong first code is a 400', $r['_code'] === 400 && str_starts_with($r['error'], 'Wrong code.'));
$app->store->set('totp_last_step', '0');
$r = $web('/api/security/totp/enable', ['password' => $pw, 'code' => Auth::totp($secret, intdiv(time(), 30))]);
check('web2 totp: the right code turns it on', ($r['totp'] ?? null) === true && $web('/api/security')['totp'] === true, $r['_raw']);
$r = $web('/api/security/totp/setup', []);
check('web2 totp: setup again while on is refused', $r['_code'] === 400 && str_contains($r['error'], 'already on'));
$r = $web('/api/login', ['password' => $pw], 'z');
check('web2 login: with two-step on, the password alone asks for the code', $r['_code'] === 401 && ($r['need_code'] ?? false) === true && str_contains($r['error'], '6-digit'));
$r = $web('/api/login', ['password' => $pw, 'code' => '000000'], 'z');
check('web2 login: a wrong code is refused', $r['_code'] === 401 && $r['error'] === 'Wrong code.');
$app->store->set('totp_last_step', '0');
$r = $web('/api/login', ['password' => $pw, 'code' => Auth::totp($secret, intdiv(time(), 30))], 'z');
check('web2 login: password and code sign in', ($r['ok'] ?? false) === true && $web('/api/security', null, 'z')['_code'] === 200, $r['_raw']);
$r = $web('/api/security/totp/disable', ['password' => $pw, 'code' => '000000']);
check('web2 totp: turning it off needs the right code', $r['_code'] === 403 && $r['error'] === 'Wrong code.');
$app->store->set('totp_last_step', '0');
$r = $web('/api/security/totp/disable', ['password' => $pw, 'code' => Auth::totp($secret, intdiv(time(), 30))]);
check('web2 totp: password and code turn it off', ($r['totp'] ?? null) === false && $web('/api/security')['totp'] === false, $r['_raw']);
check('web2 shareSize: bytes, KB and MB', $priv('shareSize', 500) === '500 B' && $priv('shareSize', 5000) === '5 KB' && $priv('shareSize', 3_250_000) === '3.1 MB');
check('web2 shareViewable: SVG and XML never, Office and text yes, other kinds no', !$priv('shareViewable', ['mime' => 'image/svg+xml', 'kind' => 'image'])
    && !$priv('shareViewable', ['mime' => 'text/xml', 'kind' => 'text']) && $priv('shareViewable', ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document+xml', 'kind' => 'office'])
    && $priv('shareViewable', ['mime' => 'text/plain', 'kind' => 'text']) && !$priv('shareViewable', ['mime' => 'application/zip', 'kind' => 'other']));
check('web2 disposition: an ASCII fallback and the exact UTF-8 name', $priv('disposition', 'inline', 'Résumé Alex.pdf') === "inline; filename=\"Resume Alex.pdf\"; filename*=UTF-8''R%C3%A9sum%C3%A9%20Alex.pdf"
    && str_starts_with($priv('disposition', 'attachment', '日本'), 'attachment; filename="file"'));
check('web2 cookie: no base path, the plain name', $priv('cookie', 'talixio') === 'talixio');
check('web2 minutes: under a minute, minutes, hours', $priv('minutes', 30) === 'a minute' && $priv('minutes', 900) === '15 minutes' && $priv('minutes', 7300) === '3 hours');

// ---- chat, search and taking a turn back ----
$r = $web('/api/chat', ['text' => 'hi', 'space' => 999]);
check('web2 chat: a space that does not exist is a 404', $r['_code'] === 404 && str_contains($r['error'], 'no longer exists'));
$r = $web('/api/chat', ['text' => '  ']);
check('web2 chat: an empty message is a 400', $r['_code'] === 400 && $r['error'] === 'empty message');
$long = str_repeat('filler words here ', 10) . 'Alex works at acme on the quarterly plan ' . str_repeat('and more words after ', 20);
$r = $web('/api/chat', ['text' => $long]);
check('web2 chat: a turn stores the message and the answer', count($r['messages'] ?? []) >= 2 && $r['messages'][0]['role'] === 'user', substr($r['_raw'], 0, 300));
$r = $web('/api/messages/search?q=a');
check('web2 search: one letter finds nothing', $r['results'] === []);
$r = $web('/api/messages/search?q=quarterly+plan&mode=quick');
$hit = $r['results'][0] ?? [];
check('web2 search: exact words, with a snippet cut on both sides', ($hit['exact'] ?? false) === true && str_starts_with($hit['snippet'], '…') && str_ends_with($hit['snippet'], '…'), json_encode($r['results']));
$r = $web('/api/messages/search?q=' . rawurlencode('acme Alex'));
check('web2 search: words in another order are found by the index', (bool)array_filter($r['results'], fn($x) => $x['exact'] === false), json_encode($r['results']));
$r = $web('/api/messages/search?q=' . rawurlencode('nothing-like-this-zz'));
check('web2 search: no match is an empty list', $r['_code'] === 200 && $r['results'] === []);

$txtId = $save('notes.txt', "Alex's notes for acme.example\n");
$r = $web('/api/chat', ['text' => 'read this', 'files' => [$txtId]]);
$msgs = $r['messages'] ?? [];
$uid = (int)($msgs[0]['id'] ?? 0); $aid = (int)($msgs[1]['id'] ?? 0);
check('web2 undo: an unknown message is a 404', $web('/api/messages/999999/undo', [])['_code'] === 404);
check('web2 undo: an answer cannot be taken back', $web("/api/messages/$aid/undo", [])['_code'] === 404);
$first = (int)$app->store->q("SELECT MIN(id) FROM messages WHERE role='user'")->fetchColumn();
$r = $web("/api/messages/$first/undo", []);
check('web2 undo: only the latest message can be changed', $r['_code'] === 409 && str_contains($r['error'], 'latest'));
$lk = fopen("$dw/agent.lock", 'c'); flock($lk, LOCK_EX);
$r = $web("/api/messages/$uid/undo", []);
flock($lk, LOCK_UN); fclose($lk);
check('web2 undo: refused while an answer is being written', $r['_code'] === 409 && str_contains($r['error'], 'Wait for the answer'));
$r = $web("/api/messages/$uid/undo", []);
check('web2 undo: gives back the text and the attached file, removes both messages', ($r['text'] ?? '') === 'read this' && ($r['files'][0]['id'] ?? 0) === $txtId
    && $r['removed'] === [$uid, $aid], $r['_raw']);

// streamed answers
$sse = $w2raw('/api/chat', 'POST', json_encode(['text' => 'hello stream', 'stream' => true]), ['Content-Type: application/json']);
check('web2 stream: events end with done and the stored messages', str_starts_with($sse['h']['content-type'] ?? '', 'text/event-stream') && str_contains($sse['body'], "event: done\n")
    && str_contains($sse['body'], 'hello stream'), substr($sse['body'], 0, 300));
$audio = $save('voice.mp3', str_repeat("\0", 64), '/Inbox', 'audio/mpeg');
$sse = $w2raw('/api/chat', 'POST', json_encode(['text' => '', 'files' => [$audio], 'stream' => true]), ['Content-Type: application/json']);
check('web2 stream: a voice note that cannot be transcribed ends with fail', str_contains($sse['body'], "event: fail\n"), substr($sse['body'], 0, 400));
$r = $web('/api/chat', ['text' => 'weekly', 'files' => [$audio], 'meeting' => true]);
$job = $app->store->q("SELECT title, kind FROM jobs ORDER BY id DESC LIMIT 1")->fetch();
check('web2 chat: a recording marked as meeting becomes a background job', $r['_code'] === 200 && ($job['kind'] ?? '') === 'meeting'
    && str_contains(end($r['messages'])['content'] ?? '', 'background'), $r['_raw']);

// ---- memory digest and activity ----
$app->store->q('INSERT INTO summaries(from_id,to_id,ts_from,ts_to,text,level,space) VALUES(1,2,?,?,?,?,0)', [time() - 99, time(), 'Alex talked about acme.', 1]);
$app->store->q('INSERT INTO summaries(from_id,to_id,ts_from,ts_to,text,level,space) VALUES(1,2,?,?,?,?,0)', [time() - 99, time(), 'A longer view.', 2]);
$r = $web('/api/memory/digest');
check('web2 digest: counts summaries per level and messages waiting', ($r['levels']['1'] ?? 0) === 1 && ($r['levels']['2'] ?? 0) === 1 && $r['waiting'] > 0, $r['_raw']);
$app->store->set('backup_last', (string)time()); $app->store->set('backup_last_file', '/var/backups/talixio-2026.tar.gz');
$r = $web('/api/activity');
check('web2 activity: memory counts and the last backup', $r['memory']['messages'] > 0 && $r['backup']['file'] === 'talixio-2026.tar.gz' && $r['memory']['embeddings_model'] === null
    && isset($r['calibration']['last_month']), substr($r['_raw'], 0, 300));

// ---- uploads ----
$tmpU = "$d/up.txt"; file_put_contents($tmpU, "uploaded by Alex\n");
$r = $w2raw('/api/files', 'POST', ['file' => new CURLFile($tmpU, 'text/plain', 'up.txt'), 'folder' => '/Projects']);
$up = json_decode($r['body'], true)['file'] ?? [];
check('web2 upload: a file lands in the folder asked', $r['code'] === 200 && ($up['folder'] ?? '') === '/Projects' && $up['name'] === 'up.txt', $r['body']);
$r = $w2raw('/api/files', 'POST', ['folder' => '/Inbox']);
check('web2 upload: no file is "Upload failed."', $r['code'] === 400 && json_decode($r['body'], true)['error'] === 'Upload failed.', $r['body']);
$big = "$d/big.bin"; file_put_contents($big, str_repeat('x', 2_200_000));
$r = $w2raw('/api/files', 'POST', ['file' => new CURLFile($big, 'application/octet-stream', 'big.bin')]);
check('web2 upload: over the server limit is "File too large."', $r['code'] === 400 && json_decode($r['body'], true)['error'] === 'File too large.', $r['body']);

// ---- serving files: inline or not, ranges, revalidation ----
$f = $w2raw("/api/files/$txtId");
check('web2 file: plain text shows inline with a charset', $f['code'] === 200 && $f['h']['content-type'] === 'text/plain; charset=utf-8'
    && str_starts_with($f['h']['content-disposition'], 'inline') && $f['body'] === "Alex's notes for acme.example\n", json_encode($f['h']));
$r = $w2raw("/api/files/$txtId/file/notes.txt", 'GET', null, ['If-None-Match: ' . $f['h']['etag']]);
check('web2 file: the same ETag answers 304 without a body', $r['code'] === 304 && $r['body'] === '');
$r = $w2raw("/api/files/$txtId", 'GET', null, ['Range: bytes=0-3']);
check('web2 file: a range is a 206 with its part', $r['code'] === 206 && $r['body'] === 'Alex' && $r['h']['content-range'] === 'bytes 0-3/30', json_encode($r['h']));
$r = $w2raw("/api/files/$txtId", 'GET', null, ['Range: bytes=-8']);
check('web2 file: a suffix range gives the last bytes', $r['code'] === 206 && $r['body'] === "example\n");
$r = $w2raw("/api/files/$txtId", 'GET', null, ['Range: bytes=40-']);
check('web2 file: a range past the end is a 416', $r['code'] === 416 && $r['h']['content-range'] === 'bytes */30');
$r = $w2raw("/api/files/$txtId?download=1");
check('web2 file: download=1 says attachment', $r['code'] === 200 && $r['body'] !== '' && str_starts_with($r['h']['content-disposition'], 'attachment')
    && $r['h']['content-type'] === 'application/octet-stream', $r['code'] . json_encode($r['h']));
check('web2 file: an unknown file is a 404', $w2raw('/api/files/999999')['code'] === 404);
$svg = $save('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', '/Inbox', 'image/png');
$r = $w2raw("/api/files/$svg");
check('web2 file: an "image" that is really SVG is downloaded', $r['h']['content-type'] === 'application/octet-stream' && str_starts_with($r['h']['content-disposition'], 'attachment'));
$e = $save('empty.txt', '');
$r = $w2raw("/api/files/$e");
check('web2 file: an empty file is a 200 with no body', $r['code'] === 200 && $r['body'] === '' && $r['h']['content-length'] === '0');
check('web2 showsInline: the type given and the content must agree', Web::showsInline('image', 'image/png', 'image/png') && !Web::showsInline('image', 'image/png', 'text/html')
    && Web::showsInline('pdf', 'application/pdf', 'application/pdf') && !Web::showsInline('pdf', 'application/pdf', 'text/plain')
    && Web::showsInline('audio', 'audio/ogg', 'application/ogg') && !Web::showsInline('audio', 'text/plain', 'audio/ogg')
    && Web::showsInline('text', 'text/csv', 'inode/x-empty') && !Web::showsInline('text', 'text/x-php', 'text/plain')
    && !Web::showsInline('office', 'application/msword', 'application/msword') && !Web::showsInline('image', 'image/svg+xml', 'image/svg+xml'));

// pages, previews and Office as PDF
$md = $save('readme.md', "# Plan for acme\n\nAlex writes **this**.\n");
$r = $w2raw("/api/files/$md/view/readme.md");
check('web2 view: Markdown becomes a sandboxed page', $r['code'] === 200 && str_contains($r['body'], '<strong>this</strong>') && str_contains($r['h']['content-security-policy'], 'sandbox'), substr($r['body'], 0, 200));
$r = $w2raw("/api/files/$txtId/view");
check('web2 view: plain text is a page too', $r['code'] === 200 && str_contains($r['h']['content-type'], 'text/html'));
$img = (function () { $i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 100)); ob_start(); imagepng($i); return (string)ob_get_clean(); })();
$imgId = $save('pic.png', $img, '/Inbox', 'image/png');
$r = $w2raw("/api/files/$imgId/view");
check('web2 view: an image is sent as itself', $r['code'] === 200 && $r['h']['content-type'] === 'image/png' && $r['body'] === $img);
check('web2 view: an unknown file is a 404', $w2raw('/api/files/999999/view')['code'] === 404);
$r = $w2raw("/api/files/$imgId/thumb");
check('web2 thumb: an image has a JPEG thumbnail', $r['code'] === 200 && $r['h']['content-type'] === 'image/jpeg' && str_starts_with($r['body'], "\xFF\xD8"));
$r = $w2raw("/api/files/$txtId/page/1");
check('web2 page: a text file has no pages', $r['code'] === 404);
$r = $w2raw("/api/files/$txtId/thumb");
check('web2 thumb: no thumbnail for text is a 404', $r['code'] === 404 && json_decode($r['body'], true)['error'] === 'no preview', $r['body']);
check('web2 pdf: only Office files convert', $w2raw("/api/files/$txtId/pdf")['code'] === 404);
$docx = (function () use ($d) {
    $z = new ZipArchive(); $p = "$d/memo.docx"; $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Memo for acme.example</w:t></w:r></w:p></w:body></w:document>');
    $z->close(); return (string)file_get_contents($p);
})();
$docId = $save('memo.docx', $docx, '/Shared/Sub');
$r = $w2raw("/api/files/$docId/pdf/memo.pdf");
check('web2 pdf: a Word file is shown as PDF', $r['code'] === 200 && $r['h']['content-type'] === 'application/pdf' && str_starts_with($r['body'], '%PDF')
    && str_contains($r['h']['content-disposition'], 'memo.pdf'), substr($r['body'], 0, 100));

// text and preview as data
$r = $web("/api/files/$md/text");
check('web2 text: a text file gives its source', $r['text'] === "# Plan for acme\n\nAlex writes **this**.\n" && $r['pages'] === null && $r['truncated'] === false);
$r = $web("/api/files/$docId/text");
check('web2 text: an Office file counts its pages', $r['_code'] === 200 && $r['pages'] === 1, $r['_raw']);
$app->store->q("UPDATE files SET status = 'ready', text = 'A green picture' WHERE id = ?", [$imgId]);
$r = $web("/api/files/$imgId/text");
check('web2 text: a file read by the index gives that text', $r['text'] === 'A green picture' && $r['pages'] === null);
$nt0 = $save('note.html', '<p>Alex</p>', '/Inbox', 'text/html'); $app->store->q('UPDATE files SET note = 1 WHERE id = ?', [$nt0]);
check('web2 text: a note gives its source', $web("/api/files/$nt0/text")['text'] === '<p>Alex</p>');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$pdfId = $save('plan.pdf', $pdf, '/Inbox', 'application/pdf');
$r = $w2raw("/api/files/$pdfId");
check('web2 file: a PDF shows inline in the browser viewer, without the sandbox', $r['code'] === 200 && $r['h']['content-type'] === 'application/pdf'
    && $r['h']['content-security-policy'] === "default-src 'none'; object-src 'self'", json_encode($r['h']));
check('web2 text: an unknown file is a 404', $web('/api/files/999999/text')['_code'] === 404);
$r = $web("/api/files/$md/preview");
check('web2 preview: rendered page as data', $r['_code'] === 200 && str_contains($r['html'], 'Plan for acme') && $r['file']['id'] === $md);
$r = $web("/api/files/$imgId/preview");
check('web2 preview: no page preview for an image', $r['_code'] === 400 && str_starts_with($r['error'], 'No page preview'));
$gone = $save('gone.md', 'x'); unlink($app->files()->get($gone)['path']);
$r = $web("/api/files/$gone/preview");
check('web2 preview: a file missing on disk is a 404', $r['_code'] === 404 && str_contains($r['error'], 'missing'));
check('web2 preview: an unknown file is a 404', $web('/api/files/999999/preview')['_code'] === 404);

// a library .py as a script
$r = $web("/api/files/$txtId/as-script", []);
check('web2 as-script: only .py files', $r['_code'] === 400 && str_contains($r['error'], '.py'));
$py = $save('tidy_names.py', "\"\"\"Tidy file names for Alex.\"\"\"\nprint('ok')\n");
$r = $web("/api/files/$py/as-script", []);
check('web2 as-script: becomes a script named after the file', ($r['name'] ?? '') === 'tidy-names' && $r['replaced'] === false && is_bool($r['enabled']), $r['_raw']);
$r = $web("/api/files/$py/as-script", []);
check('web2 as-script: a second time replaces it', ($r['replaced'] ?? null) === true);
$bad = $save('___.py', "print(1)\n");
$r = $web("/api/files/$bad/as-script", []);
check('web2 as-script: a name without letters is a 400', $r['_code'] === 400 && str_contains($r['error'], 'name'));
$bigPy = $save('huge.py', str_repeat('#', 1_100_000));
check('web2 as-script: over 1 MB is too big', $web("/api/files/$bigPy/as-script", [])['error'] === 'too big for a script');
check('web2 as-script: an unknown file is a 404', $web('/api/files/999999/as-script', [])['_code'] === 404);

// moving, renaming, reindexing, deleting
$mv = $save('move-me.txt', 'm');
$r = $web("/api/files/$mv/move", ['folder' => '/Projects']);
check('web2 files: move', ($r['file']['folder'] ?? '') === '/Projects');
$r = $web("/api/files/$mv/rename", ['name' => 'moved.txt']);
check('web2 files: rename', ($r['file']['name'] ?? '') === 'moved.txt');
$tw = $save('moved.txt', 'second'); // saved elsewhere, then brought next to the first
$r = $web("/api/files/$tw/move", ['folder' => '/Projects']);
check('web2 versions: a move onto a taken name asks (409)', $r['_code'] === 409 && !empty($r['conflict']), $r['_raw']);
$r = $web("/api/files/$tw/move", ['folder' => '/Projects', 'replace' => true]);
check('web2 versions: replace keeps the first file, with a version', ($r['file']['id'] ?? 0) === $mv, $r['_raw']);
$vs = $web("/api/files/$mv/versions")['versions'] ?? [];
check('web2 versions: listed', count($vs) === 1, json_encode($vs));
$r = $w2raw("/api/files/$mv/versions/{$vs[0]['id']}/file");
check('web2 versions: downloaded as an attachment', $r['code'] === 200 && $r['body'] === 'm' && str_contains($r['h']['content-disposition'] ?? '', 'attachment'), $r['body']);
check('web2 versions: an unknown version is a 404', $w2raw("/api/files/$mv/versions/999999/file")['code'] === 404);
$r = $web("/api/files/$mv/versions/{$vs[0]['id']}/restore", []);
check('web2 versions: restore', ($r['file']['id'] ?? 0) === $mv && count($web("/api/files/$mv/versions")['versions'] ?? []) === 1, $r['_raw']);
$r = $w2raw('/api/files', 'POST', ['file' => new CURLFile($tmpU, 'text/plain', 'moved.txt'), 'folder' => '/Projects', 'replace' => '1']);
check('web2 versions: an upload with replace updates the file of that name', ($j = json_decode($r['body'], true))['replaced'] === true && $j['file']['id'] === $mv, $r['body']);
$r = $web("/api/files/$mv/reindex", []);
check('web2 files: reindex puts it back in the queue', ($r['file']['status'] ?? '') === 'pending');
check('web2 files: reindex of an unknown file is a 404', $web('/api/files/999999/reindex', [])['_code'] === 404);
$r = $web("/api/files/$mv/delete", []);
check('web2 files: delete', ($r['ok'] ?? false) === true && (($g = $app->files()->get($mv)) === null || !empty($g['deleted_at'])));

// zip of a folder
$r = $w2raw('/api/library/zip?folder=/Projects');
check('web2 zip: a folder downloads as a zip', $r['code'] === 200 && $r['h']['content-type'] === 'application/zip' && str_starts_with($r['body'], 'PK')
    && str_contains($r['h']['content-disposition'], 'Projects-') && $r['h']['cache-control'] === 'no-store');
$r = $w2raw('/api/library/zip');
check('web2 zip: the whole library is named Library', $r['code'] === 200 && str_contains($r['h']['content-disposition'], 'Library-'));
$hz = $save('huge.iso', 'x', '/Huge'); $app->store->q('UPDATE files SET size = 5000000000 WHERE id = ?', [$hz]);
$r = $w2raw('/api/library/zip?folder=/Huge');
check('web2 zip: over 4 GB is refused', $r['code'] === 400 && str_contains($r['body'], 'too large'));

// ---- privacy journal ----
$sp = $app->spaces()->save(['name' => 'Acme deal']);
$app->store->q('INSERT INTO egress(ts, service, purpose, bytes, space, level, blocked) VALUES(?,?,?,?,?,?,?)', [time(), 'api.example', 'model', 120, 0, 'internal', 0]);
$app->store->q('INSERT INTO egress(ts, service, purpose, bytes, space, level, blocked) VALUES(?,?,?,?,?,?,?)', [time(), 'api.example', 'search', 50, $sp['id'], 'confidential', 1]);
$app->store->q('INSERT INTO egress(ts, service, purpose, bytes, space, level, blocked) VALUES(?,?,?,?,?,?,?)', [time(), 'api.example', 'model', 7, 4242, 'internal', 0]);
$r = $w2raw('/api/privacy/journal.csv');
$rows = array_map('str_getcsv', array_filter(explode("\n", $r['body'])));
check('web2 journal: a CSV with spaces by name and what was blocked', $r['code'] === 200 && str_starts_with($r['h']['content-type'], 'text/csv') && count($rows) === 4
    && $rows[1][4] === 'General' && $rows[2][4] === 'Acme deal' && $rows[2][6] === 'yes' && $rows[3][4] === '4242' && $rows[1][6] === 'no', $r['body']);

// ---- jobs and tasks ----
$j1 = $app->store->addJob('Report for acme', 'write it', 'standard', 0.5, 'agent', null, 0, ['acme']);
$j2 = $app->store->addJob('Old report', 'write it', 'standard', 0.5);
$app->store->q('UPDATE jobs SET report_file = ? WHERE id = ?', [$md, $j1]);
$app->store->q('UPDATE jobs SET report_file = ? WHERE id = ?', [$mv, $j2]);
$rt = $app->store->addTask('Weekly acme', 'again', '0 9 * * 1', time() + 3600, 'standard', 0, $j1);
$r = $web('/api/jobs');
$byId = array_column($r['jobs'] ?? [], null, 'id');
check('web2 jobs: report where it is now, or deleted, and the routines', ($byId[$j1]['report']['id'] ?? 0) === $md && ($byId[$j2]['report']['deleted'] ?? false) === true
    && (int)($byId[$j1]['routines'][0]['id'] ?? 0) === $rt && $byId[$j2]['routines'] === [] && isset($r['tags']['acme']), json_encode($byId[$j1] ?? null) . json_encode($r['tags'] ?? null));
$r = $web('/api/jobs?space=0&tag=acme&archived=1&q=report');
check('web2 jobs: filters, none archived', $r['_code'] === 200 && $r['jobs'] === [] && $r['archived_count'] === 0);
$r = $web('/api/jobs?space=0&tag=acme&q=acme');
check('web2 jobs: filter by space, tag and words', count($r['jobs']) === 1 && $r['jobs'][0]['id'] === $j1);

check('web2 tasks: an unknown task is a 404', $web('/api/tasks/999999/toggle', [])['_code'] === 404);
check('web2 tasks: toggle pauses an active task', ($web("/api/tasks/$rt/toggle", [])['ok'] ?? false) && (int)$app->store->task($rt)['enabled'] === 0);
check('web2 tasks: toggle restarts a paused routine at its next time', ($web("/api/tasks/$rt/toggle", [])['ok'] ?? false) && (int)$app->store->task($rt)['enabled'] === 1
    && (int)$app->store->task($rt)['next_run'] > time());
$past = $app->store->addTask('Call Alex', 'call', null, time() - 3600); $app->store->updateTask($past, ['enabled' => 0]);
$r = $web("/api/tasks/$past/toggle", []);
check('web2 tasks: a paused one-off in the past cannot restart', $r['_code'] === 400 && str_contains($r['error'], 'in the past'));
$fut = $app->store->addTask('Call Alex later', 'call', null, time() + 7200); $app->store->updateTask($fut, ['enabled' => 0]);
check('web2 tasks: a paused one-off in the future restarts', ($web("/api/tasks/$fut/toggle", [])['ok'] ?? false) && (int)$app->store->task($fut)['enabled'] === 1);
check('web2 tasks: delete', ($web("/api/tasks/$fut/delete", [])['ok'] ?? false) && $app->store->task($fut) === null);

// ---- links for people outside ----
$sh = $app->shares();
foreach ([[['folder' => '/'], [], 'whole library'], [['folder' => '/Nope'], [], 'No folder'], [['file' => 999999], [], 'No such file'],
          [['folder' => '/Shared'], ['days' => 4000], 'days'], [['folder' => '/Shared'], ['password' => 'ab'], '4 characters']] as [$what, $o, $want]) {
    try { $sh->create($what, $o); $msg = 'created'; } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
    check("web2 shares: refused ($want)", str_contains($msg, $want), $msg);
}
$app->spaces()->save(['name' => 'Vault', 'folder' => '/Vault', 'privacy' => 'secret']);
$app->spaces()->save(['name' => 'Clients', 'folder' => '/Clients', 'privacy' => 'confidential']);
$vaultFile = $save('key.txt', 's', '/Vault');
foreach ([[['folder' => '/'], 'whole library'], [['file' => $vaultFile], 'Secret space'], [['folder' => '/Vault'], 'Secret space']] as [$what, $want]) {
    try { $sh->create($what, ['days' => 7]); $msg = 'created'; } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
    check("web2 shares: secret stays inside ($want)", str_contains($msg, $want), $msg);
}
try { $sh->create(['folder' => '/Clients'], ['days' => 7]); $msg = 'created'; } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
check('web2 shares: a confidential folder needs a password', str_contains($msg, 'password is required'), $msg);
try { $sh->create(['folder' => '/Clients'], ['days' => 0, 'password' => 'abcd']); $msg = 'created'; } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
check('web2 shares: a confidential folder has an end date', str_contains($msg, '30 days'), $msg);
$save('Alex.txt', 'client file', '/Clients');
$conf = $sh->create(['folder' => '/Clients'], ['days' => 10, 'password' => 'abcd', 'by' => 'assistant', 'download' => false, 'notify' => false, 'label' => 'Alex']);
check('web2 shares: a confidential link with password, made by the assistant', $conf['password'] === true && $conf['created_by'] === 'assistant' && $conf['download'] === false
    && $conf['notify'] === false && $conf['state'] === 'active' && $conf['name'] === 'Clients');
check('web2 shares: a malformed token finds nothing', $sh->byToken('bad token!') === null);
check('web2 shares: list by folder', count($sh->list('active', null, '/Clients')) === 1 && $sh->list('all', null, '/Nope') === []);
check('web2 shares: nothing old to purge', $sh->purge() === 0);
$tmpShare = $save('temp.txt', 't', '/Inbox');
$ts = $sh->create(['file' => $tmpShare], ['days' => 1]);
$app->store->q('UPDATE shares SET expires = ? WHERE id = ?', [time() - 10, $ts['id']]);
check('web2 shares: an expired link says so', $sh->get($ts['id'])['state'] === 'expired');
$app->store->q('UPDATE shares SET expires = NULL WHERE id = ?', [$ts['id']]);
$app->library()->deleteFile($tmpShare); $app->store->q('DELETE FROM files WHERE id = ?', [$tmpShare]);
check('web2 shares: a link to a file removed for good is missing', $sh->get($ts['id'])['state'] === 'missing' && $sh->get($ts['id'])['name'] === '?');
$sh->revoke($ts['id']);
check('web2 shares: a revoked link is revoked', $sh->get($ts['id'])['state'] === 'revoked');
$app->store->q('UPDATE shares SET revoked = ? WHERE id = ?', [time() - 100 * 86400, $ts['id']]);
check('web2 shares: purge forgets a link revoked long ago', $sh->purge() === 1 && $sh->get($ts['id']) === null);
\Talixio\Shares::remember($app);
check('web2 shares: no browser host, no address remembered', $app->store->get('public_url') === '' || str_starts_with($app->store->get('public_url'), 'http://127.0.0.1'));

// the visitor's side
$tok = fn(array $s) => substr($s['url'], strrpos($s['url'], '/s/') + 3);
$visit = fn(string $path, string $method = 'GET', $fields = null, array $h = []) => $w2raw($path, $method, $fields, $h, 'visitor');
$save('photo.png', $img, '/Shared');
$binId = $save('data.bin', random_bytes(40), '/Shared', 'application/octet-stream');
$mdS = $save('agenda.md', "# Agenda\n\n- acme\n", '/Shared');
$txtS = $save('plain.txt', "plain words\n", '/Shared');
$open = $sh->create(['folder' => '/Shared'], ['message' => "Hello Alex,\nthe files.", 'days' => 5]);
$r = $visit('/s/' . $tok($open), 'GET', null, ['Accept-Language: fr-FR']);
check('web2 share page: listing with message, subfolder, kinds, zip and a French date', $r['code'] === 200 && str_contains($r['body'], '<blockquote>Hello Alex,<br />')
    && str_contains($r['body'], 'Sub · ') && str_contains($r['body'], '<span class="th ext">BIN</span>') && str_contains($r['body'], '/zip"')
    && str_contains($r['body'], date('d/m/Y', time() + 5 * 86400)) && str_contains($r['body'], 'class="th" loading="lazy"'), substr($r['body'], -1500));
$r = $visit('/s/' . $tok($open) . '/zip');
check('web2 share page: the whole folder as a zip', $r['code'] === 200 && str_starts_with($r['body'], 'PK'));
$r = $visit('/s/' . $tok($open) . "/view/$docId");
check('web2 share page: a Word file opens as PDF', $r['code'] === 200 && $r['h']['content-type'] === 'application/pdf');
$r = $visit('/s/' . $tok($open) . "/view/$mdS");
check('web2 share page: Markdown opens as a page', $r['code'] === 200 && str_contains($r['body'], '<h1') && str_contains($r['h']['content-type'], 'text/html'));
$r = $visit('/s/' . $tok($open) . "/view/$binId");
check('web2 share page: other kinds are downloaded when allowed', $r['code'] === 200 && $r['h']['content-type'] === 'application/octet-stream');
$r = $visit('/s/' . $tok($open) . "/thumb/$docId");
check('web2 share page: an Office file has a thumbnail', $r['code'] === 200 && $r['h']['content-type'] === 'image/jpeg');
$r = $visit('/s/' . $tok($open) . "/view/$txtId");
check('web2 share page: a file outside the link is not found', $r['code'] === 404);
$ro = $sh->create(['folder' => '/Shared'], ['download' => false, 'days' => 0]);
$r = $visit('/s/' . $tok($ro));
check('web2 share page: view only, no end date, no download links', $r['code'] === 200 && str_contains($r['body'], 'View only.') && str_contains($r['body'], 'Shared with you.')
    && !str_contains($r['body'], '/download/') && !str_contains($r['body'], '/zip"'));
check('web2 share page: view only refuses the zip', $visit('/s/' . $tok($ro) . '/zip')['code'] === 403);
check('web2 share page: view only refuses kinds that only download', $visit('/s/' . $tok($ro) . "/view/$binId")['code'] === 403);
$r = $visit('/s/' . $tok($ro) . "/view/$txtS");
check('web2 share page: plain text opens as a page', $r['code'] === 200 && str_contains($r['body'], 'plain words'));
$r = $visit('/s/' . $tok($ro) . "/view/" . $app->store->q("SELECT id FROM files WHERE name='photo.png'")->fetchColumn());
check('web2 share page: an image is sent as itself', $r['code'] === 200 && $r['h']['content-type'] === 'image/png');
$one = $sh->create(['file' => $md], ['days' => 3]);
$r = $visit('/s/' . $tok($one));
check('web2 share page: a single file is titled by its name', $r['code'] === 200 && str_contains($r['body'], '<title>readme.md</title>') && str_contains($r['body'], 'Available until'));
check('web2 share page: a single file has no zip', $visit('/s/' . $tok($one) . '/zip')['code'] === 403);
$app->library()->createFolder('/Empty');
$em = $sh->create(['folder' => '/Empty']);
check('web2 share page: an empty folder says so', str_contains($visit('/s/' . $tok($em))['body'], 'This folder is empty.'));
$r = $visit('/s/' . $tok($conf));
check('web2 share page: a password link asks for it', $r['code'] === 200 && str_contains($r['body'], 'type="password"'));
$r = $visit('/s/' . $tok($conf), 'POST', http_build_query(['password' => 'nope']));
check('web2 share page: a wrong password is a 403', $r['code'] === 403 && str_contains($r['body'], 'Wrong password.'));
$r = $visit('/s/' . $tok($conf), 'POST', http_build_query(['password' => 'abcd']));
check('web2 share page: the right password redirects with a cookie', $r['code'] === 303 && ($r['h']['location'] ?? '') === '/s/' . $tok($conf) && str_contains($r['h']['set-cookie'] ?? '', 'tlxs' . $conf['id']));
$r = $visit('/s/' . $tok($conf));
check('web2 share page: then the files show', $r['code'] === 200 && str_contains($r['body'], 'Alex.txt'));
$lock = $sh->create(['folder' => '/Shared'], ['password' => 'right-one']);
for ($i = 0; $i < 10; $i++) $visit('/s/' . $tok($lock), 'POST', http_build_query(['password' => "wrong$i"]));
$r = $visit('/s/' . $tok($lock), 'POST', http_build_query(['password' => 'right-one']));
check('web2 share page: after 10 wrong passwords even the right one waits', $r['code'] === 403 && str_contains($r['body'], 'Too many wrong passwords'));

$r = $visit('/s/' . $tok($open) . "/download/$txtS");
check('web2 share page: download sends an attachment', $r['code'] === 200 && str_starts_with($r['h']['content-disposition'], 'attachment') && $r['body'] === "plain words\n");
$r = $visit('/s/' . $tok($open) . "/download/$txtS", 'HEAD');
check('web2 share page: HEAD of a download has headers and no body', $r['code'] === 200 && $r['body'] === '' && $r['h']['content-length'] === '12', json_encode($r['h']));
check('web2 share page: view only refuses a download', $visit('/s/' . $tok($ro) . "/download/$txtS")['code'] === 403);
$r = $visit('/s/' . str_repeat('Z', 24));
check('web2 share page: an unknown link is a 404 page', $r['code'] === 404 && str_contains($r['body'], 'Link not available') && ($r['h']['x-robots-tag'] ?? '') === 'noindex, nofollow');
$log = $sh->accessLog($open['id']);
check('web2 shares: the access log lists what was opened, newest first', count($log) >= 5 && $log[0]['action'] === 'download' && in_array('zip', array_column($log, 'action'), true));
$idx = $sh->sharedIndex();
check('web2 shares: the index marks shared files and folders', in_array($md, $idx['files'], true) && in_array('/Shared', $idx['folders'], true) && in_array('/Clients', $idx['folders'], true));
check('web2 shares: list by file, and all of them', count($sh->list('active', $md)) === 1 && count($sh->list('all')) >= count($sh->list()));
$u = $sh->update($one['id'], ['label' => ' For Alex ', 'message' => 'Hi', 'days' => 0, 'password' => 'abcd', 'download' => false, 'notify' => true]);
check('web2 shares: update label, message, end, password, download and notify', $u['label'] === 'For Alex' && $u['message'] === 'Hi' && $u['expires'] === null
    && $u['password'] === true && $u['download'] === false && $u['notify'] === true);
$u = $sh->update($one['id'], ['password' => '', 'days' => 2]);
check('web2 shares: an empty password removes it', $u['password'] === false && $u['expires'] > time());
check('web2 shares: an update with nothing changes nothing', $sh->update($one['id'], [])['label'] === 'For Alex');
foreach ([[999999, [], 'No such link'], [$one['id'], ['days' => -1], '0 to 3650'], [$one['id'], ['password' => 'ab'], '4 characters'],
          [$conf['id'], ['days' => 0], '30 days'], [$conf['id'], ['password' => ''], 'password is required']] as [$sid, $o, $want]) {
    try { $sh->update($sid, $o); $msg = 'updated'; } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
    check("web2 shares: update refused ($want)", str_contains($msg, $want), $msg);
}
$nt = $sh->create(['file' => $txtS], ['notify' => true, 'label' => 'Alex']);
$r = $visit('/s/' . $tok($nt)); $visit('/s/' . $tok($nt));
check('web2 share page: openings are counted (the first one notifies)', $r['code'] === 200 && (int)$sh->get($nt['id'])['views'] === 2 && $sh->get($nt['id'])['last_access'] > 0);
$sh->revoke($one['id']);
check('web2 shares: a revoked token no longer opens', $sh->byToken($tok($one)) === null && $visit('/s/' . $tok($one))['code'] === 404);
$app->store->q('UPDATE shares SET expires = ? WHERE id = ?', [time() - 5, $em['id']]);
check('web2 shares: an expired token no longer opens', $sh->byToken($tok($em)) === null);
$app->library()->createFolder('/Shared/Hidden');
$hid = $save('hidden.txt', 'h', '/Shared/Hidden');
$app->spaces()->save(['name' => 'Hidden', 'folder' => '/Shared/Hidden', 'privacy' => 'secret']);
$gone2 = $save('later.txt', 'l', '/Shared'); $app->store->q('DELETE FROM files WHERE id = ?', [$gone2]);
$fl = $sh->files($sh->byToken($tok($open)));
check('web2 shares: a file moved into a secret space leaves the link', !isset($fl[$hid]) && isset($fl[$txtS]));

// ---- the network drive ----
$dv = $web('/api/dav/password', ['current' => $pw]);
$davPw = (string)($dv['password'] ?? '');
check('web2 dav: a new password turns the drive on read only', strlen($davPw) > 20 && $dv['mode'] === 'read', $dv['_raw']);
check('web2 dav: an unknown mode is a 400', $web('/api/dav', ['mode' => 'fast'])['_code'] === 400);
$dav = function (string $method, string $path, ?string $body = null, array $h = [], ?string $user = null) use ($port, &$davPw) {
    $ch = curl_init("http://127.0.0.1:$port/dav$path");
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['X-Forwarded-For: 203.0.113.7'], $h),
        CURLOPT_HEADERFUNCTION => function ($c, $x) use (&$hdr) { if (str_contains($x, ':')) { [$k, $v] = explode(':', $x, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($x); }]
        + ($method === 'HEAD' ? [CURLOPT_NOBODY => true] : []) + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []) + ($user === '' ? [] : [CURLOPT_USERPWD => $user ?? "talixio:$davPw"]));
    $out = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $out, 'h' => $hdr];
};
$r = $dav('OPTIONS', '/');
check('web2 dav: OPTIONS in read mode offers no writing', $r['code'] === 200 && !str_contains($r['h']['allow'], 'PUT') && str_contains($r['h']['allow'], 'PROPPATCH'));
$r = $dav('PUT', '/Inbox/new.txt', 'x');
check('web2 dav: read only refuses PUT', $r['code'] === 403 && str_contains($r['body'], 'read only'));
$web('/api/dav', ['mode' => 'write']);
check('web2 dav: OPTIONS in write mode offers PUT', str_contains($dav('OPTIONS', '/')['h']['allow'], 'PUT'));
check('web2 dav: a wrong password is a 401', $dav('PROPFIND', '/', null, [], 'talixio:wrong')['code'] === 401);
check('web2 dav: an unknown method is a 405', $dav('REPORT', '/')['code'] === 405);
check('web2 dav: PUT on the root is a 405', $dav('PUT', '/', 'x')['code'] === 405);
check('web2 dav: PUT a new file is a 201', $dav('PUT', '/Inbox/drive.txt', 'from the drive')['code'] === 201);
check('web2 dav: PUT again replaces it (204)', $dav('PUT', '/Inbox/drive.txt', 'changed')['code'] === 204
    && $dav('GET', '/Inbox/drive.txt')['body'] === 'changed');
check('web2 dav: PUT on a folder name is a 405', $dav('PUT', '/Inbox', 'x')['code'] === 405);
check('web2 dav: PUT into a missing folder is a 409', $dav('PUT', '/Nope/x.txt', 'x')['code'] === 409);
check('web2 dav: PUT into a secret folder is a 404', $dav('PUT', '/Vault/x.txt', 'x')['code'] === 404);
\Talixio\Library::$system = true; try { $app->library()->createFolder('/FAQ'); } finally { \Talixio\Library::$system = false; }
check('web2 dav: PUT into the FAQ is refused', ($r = $dav('PUT', '/FAQ/x.txt', 'x'))['code'] === 403, $r['code'] . $r['body']);
check('web2 dav: ".." in a path is resolved', $dav('GET', '/Projects/../Inbox/drive.txt')['body'] === 'changed');
// clutter
check('web2 dav: clutter is kept aside (201, then 204)', $dav('PUT', '/Inbox/.DS_Store', 'junk')['code'] === 201 && $dav('PUT', '/Inbox/.DS_Store', 'junk2')['code'] === 204
    && !$app->store->q("SELECT 1 FROM files WHERE name = '.DS_Store'")->fetchColumn());
$r = $dav('PROPFIND', '/Inbox/.DS_Store', null, ['Depth: 0']);
check('web2 dav: PROPFIND on clutter', $r['code'] === 207 && str_contains($r['body'], '<d:getcontentlength>5</d:getcontentlength>'));
check('web2 dav: PROPFIND on missing clutter is a 404', $dav('PROPFIND', '/Inbox/Thumbs.db')['code'] === 404);
check('web2 dav: GET clutter', $dav('GET', '/Inbox/.DS_Store')['body'] === 'junk2' && $dav('GET', '/Inbox/._x')['code'] === 404);
$dest = fn(string $p) => "Destination: http://127.0.0.1:$port/dav$p";
check('web2 dav: COPY clutter to another clutter name', $dav('COPY', '/Inbox/.DS_Store', null, [$dest('/Inbox/._copy')])['code'] === 201 && $dav('GET', '/Inbox/._copy')['body'] === 'junk2');
check('web2 dav: MOVE clutter to a real name drops it', $dav('MOVE', '/Inbox/._copy', null, [$dest('/Inbox/real.txt')])['code'] === 201 && $dav('GET', '/Inbox/._copy')['code'] === 404);
check('web2 dav: MOVE missing clutter is a 404', $dav('MOVE', '/Inbox/._none', null, [$dest('/Inbox/._x2')])['code'] === 404);
check('web2 dav: DELETE clutter', $dav('DELETE', '/Inbox/.DS_Store')['code'] === 204 && $dav('GET', '/Inbox/.DS_Store')['code'] === 404);
// same names
$a1 = $save('dup.txt', 'one', '/Projects'); $a2 = $save('dup.txt', 'two', '/Projects');
$n1 = $save('README', 'r1', '/Projects'); $n2 = $save('README', 'r2', '/Projects');
// a name is used once per folder now (the second is "dup (2).txt"); duplicates from before 0.28.29, not merged yet, still show numbered
$app->store->q("UPDATE files SET name = 'dup.txt' WHERE id = ?", [$a2]); $app->store->q("UPDATE files SET name = 'README' WHERE id = ?", [$n2]);
$r = $dav('PROPFIND', '/Projects', null, ['Depth: 1']);
check('web2 dav: same names show numbered', $r['code'] === 207 && str_contains($r['body'], "dup (#$a1).txt") && str_contains($r['body'], "README (#$n1)"), substr($r['body'], 0, 300));
check('web2 dav: GET a numbered name', $dav('GET', '/Projects/' . rawurlencode("dup (#$a1).txt"))['body'] === 'one' && $dav('GET', '/Projects/dup.txt')['body'] === 'two');
check('web2 dav: a number that does not match is a 404', $dav('GET', '/Projects/' . rawurlencode("other (#$a1).txt"))['code'] === 404);
$r = $dav('PROPFIND', '/Projects/dup.txt', null, ['Depth: 0']);
check('web2 dav: PROPFIND on a file', $r['code'] === 207 && str_contains($r['body'], "<d:displayname>dup.txt</d:displayname>") && str_contains($r['body'], '<d:getetag>"' . $a2 . '-3-'));
$gonePath = $app->files()->get($a1)['path']; unlink($gonePath);
check('web2 dav: a file missing on disk is a 404 but still listed', $dav('GET', '/Projects/' . rawurlencode("dup (#$a1).txt"))['code'] === 404
    && str_contains($dav('PROPFIND', '/Projects/' . rawurlencode("dup (#$a1).txt"), null, ['Depth: 0'])['body'], '<d:getcontentlength>3</d:getcontentlength>'));
check('web2 dav: PROPFIND on nothing is a 404', $dav('PROPFIND', '/Nope')['code'] === 404);
$r = $dav('GET', '/Projects/');
check('web2 dav: a folder in a browser is a list of links', $r['code'] === 200 && str_contains($r['body'], '<a href="/dav/">..</a>') && str_contains($r['body'], 'up.txt'), substr($r['body'], 0, 300));
$r = $dav('GET', '/');
check('web2 dav: the root page hides secret folders', $r['code'] === 200 && str_contains($r['body'], 'Projects/') && !str_contains($r['body'], 'Vault'));
check('web2 dav: GET of nothing is a 404', $dav('GET', '/Nope/x')['code'] === 404);
check('web2 dav: HEAD sends no body', ($h = $dav('HEAD', '/Inbox/drive.txt'))['code'] === 200 && $h['body'] === '');
// folders
check('web2 dav: MKCOL with a body is a 415', $dav('MKCOL', '/NewF', 'x')['code'] === 415);
check('web2 dav: MKCOL on an existing folder is a 405', $dav('MKCOL', '/Projects')['code'] === 405);
check('web2 dav: MKCOL in a missing folder is a 409', $dav('MKCOL', '/Nope/Sub')['code'] === 409);
check('web2 dav: MKCOL in a secret folder is a 404', $dav('MKCOL', '/Vault/Sub')['code'] === 404);
check('web2 dav: MKCOL with a bad name is refused', $dav('MKCOL', '/' . rawurlencode(' bad '))['code'] === 403);
check('web2 dav: MKCOL makes a folder', $dav('MKCOL', '/Drive')['code'] === 201 && $dav('MKCOL', '/Drive/In')['code'] === 201 && $app->library()->exists('/Drive/In'));
check('web2 dav: DELETE nothing is a 404', $dav('DELETE', '/Nope')['code'] === 404);
check('web2 dav: DELETE the root is refused', $dav('DELETE', '/')['code'] === 403);
// move and copy
check('web2 dav: a destination off the drive is a 502', $dav('MOVE', '/Inbox/drive.txt', null, ['Destination: http://127.0.0.1/elsewhere/x'])['code'] === 502);
check('web2 dav: MOVE to the same place is a 403', $dav('MOVE', '/Inbox/drive.txt', null, [$dest('/Inbox/drive.txt')])['code'] === 403);
check('web2 dav: MOVE to the root is a 403', $dav('MOVE', '/Inbox/drive.txt', null, [$dest('/')])['code'] === 403);
check('web2 dav: MOVE of nothing is a 404', $dav('MOVE', '/Inbox/none.txt', null, [$dest('/Inbox/n2.txt')])['code'] === 404);
check('web2 dav: a folder cannot go inside itself', $dav('MOVE', '/Drive', null, [$dest('/Drive/In/Drive')])['code'] === 403);
check('web2 dav: an existing target without overwrite is a 412', $dav('COPY', '/Inbox/drive.txt', null, [$dest('/Projects/up.txt'), 'Overwrite: F'])['code'] === 412);
check('web2 dav: into a missing folder is a 409', $dav('COPY', '/Inbox/drive.txt', null, [$dest('/Nope/x.txt')])['code'] === 409);
check('web2 dav: COPY a file', $dav('COPY', '/Inbox/drive.txt', null, [$dest('/Drive/copy.txt')])['code'] === 201 && $dav('GET', '/Drive/copy.txt')['body'] === 'changed');
check('web2 dav: MOVE renames and moves', $dav('MOVE', '/Drive/copy.txt', null, [$dest('/Drive/In/renamed.txt')])['code'] === 201 && $dav('GET', '/Drive/In/renamed.txt')['body'] === 'changed');
$dav('PUT', '/Inbox/real.txt', 'real');
check('web2 dav: MOVE over a file replaces it (204)', $dav('MOVE', '/Inbox/real.txt', null, [$dest('/Drive/In/renamed.txt')])['code'] === 204);
check('web2 dav: MOVE a file onto itself in other case', $dav('MOVE', '/Inbox/drive.txt', null, [$dest('/Inbox/Drive.txt')])['code'] === 204 || $dav('GET', '/Inbox/Drive.txt')['code'] === 200);
$app->store->q("UPDATE files SET note = 1 WHERE name = 'renamed.txt'");
check('web2 dav: COPY a folder with what is in it', $dav('COPY', '/Drive', null, [$dest('/Drive2')])['code'] === 201 && $dav('GET', '/Drive2/In/renamed.txt')['code'] === 200);
check('web2 dav: MOVE a folder over another (204)', $dav('MOVE', '/Drive2', null, [$dest('/Drive')])['code'] === 204 && !$app->library()->exists('/Drive2'));
check('web2 dav: DELETE a file and a folder', $dav('DELETE', '/Drive/In/renamed.txt')['code'] === 204 && $dav('DELETE', '/Drive')['code'] === 204);
// locks and properties
$r = $dav('LOCK', '/Inbox/locked.docx', '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"/>', ['Depth: 0']);
check('web2 dav: LOCK on a new name reserves it (201, depth 0)', $r['code'] === 201 && str_contains($r['body'], '<d:depth>0</d:depth>') && str_starts_with($r['h']['lock-token'] ?? '', '<opaquelocktoken:'));
$r = $dav('LOCK', '/Inbox/locked.docx');
check('web2 dav: LOCK on an existing file is a 200', $r['code'] === 200 && str_contains($r['body'], '<d:depth>infinity</d:depth>'));
check('web2 dav: UNLOCK is a 204', $dav('UNLOCK', '/Inbox/locked.docx')['code'] === 204);
check('web2 dav: PROPPATCH on nothing is a 404', $dav('PROPPATCH', '/Inbox/none.txt', '<x/>')['code'] === 404);
$r = $dav('PROPPATCH', '/Projects/up.txt', '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:z="urn:schemas-microsoft-com:"><d:set><d:prop><z:Win32LastModifiedTime>x</z:Win32LastModifiedTime></d:prop></d:set></d:propertyupdate>');
check('web2 dav: PROPPATCH echoes the properties', $r['code'] === 207 && str_contains($r['body'], '<x:Win32LastModifiedTime xmlns:x="urn:schemas-microsoft-com:"/>'), $r['body']);
// clutter copied to a real name is dropped; a file moved onto a folder replaces it; a folder copy skips files gone from disk
$dav('PUT', '/Inbox/._tmp', 'j');
check('web2 dav: COPY clutter to a real name does nothing', $dav('COPY', '/Inbox/._tmp', null, [$dest('/Inbox/kept.txt')])['code'] === 201 && $dav('GET', '/Inbox/kept.txt')['code'] === 404 && $dav('GET', '/Inbox/._tmp')['body'] === 'j');
$dav('MKCOL', '/Over'); $dav('PUT', '/Inbox/over.txt', 'o');
check('web2 dav: MOVE a file over a folder replaces the folder', $dav('MOVE', '/Inbox/over.txt', null, [$dest('/Over')])['code'] === 204 && $dav('GET', '/Over')['body'] === 'o');
$dav('MKCOL', '/Cp'); $dav('PUT', '/Cp/a.txt', 'a'); $dav('PUT', '/Cp/b.txt', 'b');
unlink($app->files()->get((int)$app->store->q("SELECT id FROM files WHERE name = 'b.txt' AND folder = '/Cp'")->fetchColumn())['path']);
check('web2 dav: COPY a folder skips files missing on disk', $dav('COPY', '/Cp', null, [$dest('/Cp2')])['code'] === 201 && $dav('GET', '/Cp2/a.txt')['body'] === 'a' && $dav('GET', '/Cp2/b.txt')['code'] === 404);
// wrong passwords: each different one counts once, ten block the address, the right one still gets in
check('web2 dav: a header without user and password is a 401', $dav('PROPFIND', '/', null, ['Authorization: Basic ' . base64_encode('nocolon')], '')['code'] === 401);
for ($i = 0; $i < 10; $i++) { $dav('PROPFIND', '/', null, [], "talixio:wrong-$i"); $dav('PROPFIND', '/', null, [], "talixio:wrong-$i"); }
$r = $dav('PROPFIND', '/', null, [], 'talixio:wrong-new');
check('web2 dav: ten wrong passwords block the address (429)', $r['code'] === 429 && ($r['h']['retry-after'] ?? '') === '900' && $web('/api/dav')['blocked'] === true, $r['code'] . json_encode($r['h']) . $web('/api/dav')['_raw']);
check('web2 dav: the right password still gets in', $dav('PROPFIND', '/', null, ['Depth: 0'])['code'] === 207);
check('web2 dav: unblock lets every address try again', $web('/api/dav/unblock', [])['blocked'] === false && $dav('PROPFIND', '/', null, [], 'talixio:x')['code'] === 401);
$web('/api/dav', ['mode' => 'off']);
check('web2 dav: off answers 404', $dav('PROPFIND', '/')['code'] === 404);

// ---- signing out ----
check('web2 logout: the session ends', ($web('/api/logout', [])['ok'] ?? false) === true && $web('/api/security')['_code'] === 401);

// ---- a second instance: under a base path, a public address and a Europe-only provider ----
$db = "$d/b"; @mkdir("$db/ini", 0777, true);
file_put_contents("$d/ossl.cnf", "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
$k = openssl_pkey_new(['private_key_bits' => 2048, 'config' => "$d/ossl.cnf"]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $k, ['config' => "$d/ossl.cnf", 'digest_alg' => 'sha256']);
$x = openssl_csr_sign($csr, null, $k, 2, ['config' => "$d/ossl.cnf", 'x509_extensions' => 'ext', 'digest_alg' => 'sha256']);
openssl_x509_export($x, $crt); openssl_pkey_export($k, $kp, null, ['config' => "$d/ossl.cnf"]);
file_put_contents("$d/cert.pem", $crt . $kp); file_put_contents("$d/ca.pem", $crt);
file_put_contents("$db/ini/zz-ca.ini", "curl.cainfo=$d/ca.pem\n");
file_put_contents("$d/srv.php", <<<'PHP'
<?php
[, $port, $pem, $log] = $argv;
$s = stream_socket_server("tls://127.0.0.1:$port", $e, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]));
while (true) {
    $c = @stream_socket_accept($s, 60); if (!$c) continue;
    $head = ''; while (!str_contains($head, "\r\n\r\n") && ($l = fgets($c)) !== false) $head .= $l;
    $len = preg_match('/content-length:\s*(\d+)/i', $head, $m) ? (int)$m[1] : 0; if ($len) stream_get_contents($c, $len);
    [, $path] = explode(' ', $head) + [1 => ''];
    file_put_contents($log, "$path " . (preg_match('/authorization: (.*)\r/i', $head, $a) ? trim($a[1]) : '-') . "\n", FILE_APPEND);
    $j = json_encode(str_ends_with($path, '/models') ? ['data' => [['id' => 'mistral-small-latest'], ['id' => 'mistral-medium-latest'], ['id' => 'mistral-embed'], ['id' => 'voxtral-mini-latest']]]
        : ['choices' => [['message' => ['content' => 'OK']]]]);
    fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($j) . "\r\nConnection: close\r\n\r\n$j"); fclose($c);
}
PHP);
$tport = free_port();
$tp = proc_open([PHP_BINARY, '-n', '-d', 'extension=openssl', "$d/srv.php", (string)$tport, "$d/cert.pem", "$d/tls.log"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$d/tls.err", 'w']], $tpp, null, ['PATH' => getenv('PATH')]);
if (!extension_loaded('openssl') || !is_resource($tp)) $tp = null;
register_shutdown_function(function () use ($tp) { if ($tp) { proc_terminate($tp); proc_close($tp); } });
wait_port($tport);
if (!@fsockopen('127.0.0.1', $tport)) { // openssl built in: no extension line needed
    $tp = proc_open([PHP_BINARY, '-n', "$d/srv.php", (string)$tport, "$d/cert.pem", "$d/tls.log"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$d/tls.err", 'a']], $tpp, null, ['PATH' => getenv('PATH')]);
    register_shutdown_function(function () use ($tp) { proc_terminate($tp); proc_close($tp); });
    wait_port($tport);
}
$webB = web_app($db, ['TALIXIO_WEB_PASSWORD' => 'second-long-password', 'TALIXIO_BASE_PATH' => '/t', 'TALIXIO_PUBLIC_URL' => 'https://talixio.acme.example/t',
    'PHP_INI_SCAN_DIR' => (getenv('PHP_INI_SCAN_DIR') ?: '/etc/php/8.3/cli/conf.d') . ":$db/ini"]);
$r = $webB('/api/login', ['password' => 'second-long-password'], 'b');
$ck = (string)@file_get_contents("$db/ck-b");
// the cookie is for /t/ over https only; this test server answers plain http at the root, so hand it back that way
file_put_contents("$db/ck-b", preg_replace('/\t\/t\/\tTRUE\t/', "\t/\tFALSE\t", $ck));
check('web2 base path: the session cookie carries the instance mark', ($r['ok'] ?? false) && str_contains($ck, 'talixio_' . substr(sha1('/t'), 0, 8)), $ck);
$appB = $w2app($db, ['TALIXIO_PUBLIC_URL' => 'https://talixio.acme.example/t']);
$appB->library()->createFolder('/Out');
check('web2 shares: links use the public address when set', str_starts_with($appB->shares()->create(['folder' => '/Out'])['url'], 'https://talixio.acme.example/t/s/'));
$turl = "https://127.0.0.1:$tport/v1";
$r = $webB('/api/sovereign/apply', ['service' => 'mistral', 'key' => "demo\nkey", 'url' => $turl], 'b');
check('web2 sovereign: a value that does not validate is a 422 and nothing is saved', $r['_code'] === 422 && isset($r['errors']['TALIXIO_SOVEREIGN_KEY'])
    && $webB('/api/sovereign', null, 'b')['active'] === false, substr($r['_raw'], 0, 300));
$r = $webB('/api/sovereign/apply', ['service' => 'mistral', 'key' => 'demo-key', 'url' => $turl, 'only' => 1, 'light' => 1], 'b');
check('web2 sovereign: apply switches to the provider, transcription and search by meaning too', ($r['ok'] ?? false) === true && $r['active'] === true
    && $r['tiers']['fast'] === 'mistral-small-latest' && $r['only'] === true && $r['has_key'] === true && $r['url'] === $turl && $r['test']['embed'] === true
    && $r['test']['transcribe'] === true, substr($r['_raw'], 0, 500));
$cfgB = (string)@file_get_contents("$db/settings.env") . implode('', array_map('file_get_contents', glob("$db/*.env") ?: []));
$sv = $webB('/api/sovereign', null, 'b');
check('web2 sovereign: the card shows it active with its models', $sv['active'] === true && $sv['tiers']['deep'] === 'mistral-medium-latest' && isset($sv['prices']['mistral-medium-latest']), substr($sv['_raw'], 0, 300));
$r = $webB('/api/sovereign/test', ['service' => 'mistral', 'url' => $turl], 'b');
$log = (string)@file_get_contents("$d/tls.log");
check('web2 sovereign: a test without a key uses the saved one', ($r['models'] ?? 0) === 4 && substr_count($log, 'Bearer demo-key') >= 4, $r['_raw'] . ' ' . $log);
$r = $webB('/api/sovereign/apply', ['service' => 'mistral', 'key' => '', 'url' => $turl], 'b');
check('web2 sovereign: applying again without light keeps the saved key', ($r['ok'] ?? false) === true && $r['has_key'] === true && $r['only'] === false, substr($r['_raw'], 0, 300));
$r = $webB('/api/dav', null, 'b');
check('web2 dav: the drive address uses the public address', ($r['url'] ?? '') === 'https://talixio.acme.example/t/dav/', $r['_raw']);
// last: wrong passwords lock the address for sensitive changes too
for ($i = 0; $i < 5; $i++) $webB('/api/dav/password', ['current' => "wrong-$i"], 'b');
$r = $webB('/api/dav/password', ['current' => 'second-long-password'], 'b');
check('web2 lockout: after 5 wrong passwords even the right one waits', $r['_code'] === 429 && str_contains($r['error'], '15 minutes'), $r['_raw']);
$r = $webB('/api/login', ['password' => 'second-long-password'], 'c');
check('web2 lockout: signing in from that address waits too', $r['_code'] === 429 && str_contains($r['error'], 'Too many failed attempts') && str_contains($r['error'], 'minutes'), $r['_raw']);

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
