<?php
declare(strict_types=1);
// Moving a space to another assistant (src/SpaceMove.php, import tickets in src/Links.php, routes in src/Web.php).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Links, SpaceMove};

$keep = [];
foreach (['TALIXIO_DATA_DIR' => '', 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_INSTANCE' => '', 'TALIXIO_SSO_KEY' => '', 'TALIXIO_LINKS' => '', 'TALIXIO_INSTANCE_NAME' => ''] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$open = function (string $dir, string $self, string $name) { putenv("TALIXIO_DATA_DIR=$dir"); putenv("TALIXIO_INSTANCE=$self"); putenv("TALIXIO_INSTANCE_NAME=$name"); return App::fromEnv(); };

// ---- assistant A: a space with a conversation, documents, a job and its routine, tasks, what the Mind learned
$da = test_dir('move-a');
$a = $open($da, 'work', 'Work');
$sp = $a->spaces()->save(['name' => 'Acme', 'folder' => '/Clients/Acme', 'code' => 'ACM', 'instructions' => 'Be brief.', 'notes' => 'Alex is the contact.', 'privacy' => 'confidential', 'tier' => 'deep']);
$id = (int)$sp['id'];
$a->spaces()->save(['name' => 'Acme', 'folder' => '/Clients/Acme', 'code' => 'ACM', 'instructions' => 'Be brief.', 'notes' => 'Alex is the contact.', 'privacy' => 'confidential', 'tier' => 'deep', 'archived' => 0], $id);
$tmp = tempnam(sys_get_temp_dir(), 't'); file_put_contents($tmp, "Quote for Acme: 1200 EUR");
$inFolder = $a->files()->save($tmp, 'quote.txt', 'text/plain', '/Clients/Acme/Quotes');
$a->files()->text($inFolder);
$tmp = tempnam(sys_get_temp_dir(), 't'); file_put_contents($tmp, "Shared price list");
$outside = $a->files()->save($tmp, 'prices.txt', 'text/plain', '/Reference');
$a->files()->text($outside);
$tmp = tempnam(sys_get_temp_dir(), 't'); file_put_contents($tmp, "Not related");
$unrelated = $a->files()->save($tmp, 'other.txt', 'text/plain', '/Reference');
$st = $a->store;
$other = $st->addMessage('user', 'web', 'In General', null, [], 0);
$m1 = $st->addMessage('user', 'web', "Look at [attached text #$outside: prices.txt]", null, [$outside], $id);
$st->q("INSERT INTO jobs(title, instructions, tier, status, result, max_cost_usd, created, space, report_file, folder) VALUES('Survey','Survey the market','standard','done','See [the report](/files/$inFolder#p1)',1,?,?,?,?)", [time(), $id, $inFolder, '/Clients/Acme']);
$job = (int)$st->db->lastInsertId();
$st->q("INSERT INTO tool_log(ts, tool, args, result, job_id) VALUES(?, 'web_search', '{}', 'ok', ?)", [time(), $job]);
$st->q("INSERT INTO tasks(title, prompt, cron, enabled, created, space, job) VALUES('Weekly survey', '', '0 9 * * 1', 1, ?, ?, ?)", [time(), $id, $job]);
$routine = (int)$st->db->lastInsertId();
$m2 = $st->addMessage('assistant', 'web', "The quote is in [quote.txt](/files/$inFolder). See ACM-1.", ['routine' => $routine, 'tier' => 'deep'], [], $id);
$sum = $st->addSummary($m1, $m2, time() - 60, time(), 'Talked about the Acme quote.', 1, $id);
$st->set(\Talixio\Store::summarizedKey($id), (string)$m2);
$b = $a->board();
$c1 = $b->create(['title' => 'Send the quote', 'body' => "Attach [quote](/files/$inFolder)", 'links' => [['label' => 'quote', 'type' => 'file', 'ref' => $inFolder]]], $id);
$c2 = $b->create(['title' => 'Follow up after ACM-1', 'depends_on' => [$c1['id']]], $id);
$b->addTime($c1['id'], 1.5, null, 'drafting');
$st->q('UPDATE cards SET gcal_id = ?, gcal_synced = 1, in_calendar = 1 WHERE id = ?', ['evt1', $c2['id']]);
$gen = $b->create(['title' => 'General task'], 0);
$st->q("INSERT INTO mind_beliefs(kind, space, text, created) VALUES('value', ?, 'Acme prefers short emails', ?)", [$id, time()]);
$st->q("INSERT INTO mind_journal(ts, kind, text, space) VALUES(?, 'note', 'Learned about Acme', ?)", [time(), $id]);
$st->set("mind_portrait_$id", 'Acme portrait');
$a->spaces()->setForChannel('telegram', $id);

// ---- the package
$zip = (new SpaceMove($a))->export($id);
$z = new ZipArchive(); $z->open($zip);
$meta = json_decode((string)$z->getFromName('space.json'), true);
$names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
$z->close();
check('move: the package names its format and source', ($meta['format'] ?? '') === 'talixio-space' && ($meta['from']['name'] ?? '') === 'Work', json_encode($meta['from'] ?? null));
check('move: it never carries the AI account or its key', !array_key_exists('account', $meta['space']) && !str_contains((string)file_get_contents($zip), 'sk-'));
check('move: it holds the folder and the documents pointed to, not the others', in_array("files/$inFolder", $names, true) && in_array("files/$outside", $names, true) && !in_array("files/$unrelated", $names, true), json_encode($names));
check('move: the counts', $meta['counts'] === ['messages' => 2, 'tasks' => 2, 'routines' => 1, 'jobs' => 1, 'files' => 2], json_encode($meta['counts']));
check('move: peek reads it', SpaceMove::peek($zip)['space']['name'] === 'Acme');
$bad = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($bad, 'not a zip');
$threw = ''; try { SpaceMove::peek($bad); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
check('move: something else is refused', str_contains($threw, 'not a space package'), $threw);
$threw = false; try { (new SpaceMove($a))->export(0); } catch (\InvalidArgumentException) { $threw = true; }
check('move: General cannot be moved', $threw);
$st->q("INSERT INTO jobs(title, instructions, tier, status, max_cost_usd, created, space) VALUES('Busy','x','standard','running',1,?,?)", [time(), $id]);
$busy = (int)$st->db->lastInsertId();
$threw = ''; try { (new SpaceMove($a))->export($id); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
check('move: not while a job of the space runs', str_contains($threw, 'still running'), $threw);
$st->q('DELETE FROM jobs WHERE id = ?', [$busy]);

// ---- assistant B already has an "Acme" space with the ACM code and the folder in use
$bb = $open(test_dir('move-b'), 'home', 'Private');
$bb->spaces()->save(['name' => 'Acme', 'folder' => '/Clients/Acme', 'code' => 'ACM']);
$tmp = tempnam(sys_get_temp_dir(), 't'); file_put_contents($tmp, 'mine');
$bb->files()->save($tmp, 'mine.txt', 'text/plain', '/Clients/Acme');
$bb->store->q('INSERT INTO messages(role, channel, content, ts, space) VALUES(?,?,?,?,0)', ['user', 'web', 'pad', time()]); // ids differ from A's
$bb->store->q('INSERT INTO messages(role, channel, content, ts, space) VALUES(?,?,?,?,0)', ['user', 'web', 'pad', time()]);
$tmp = tempnam(sys_get_temp_dir(), 't'); file_put_contents($tmp, 'pad');
$bb->files()->save($tmp, 'pad.txt', 'text/plain', '/Inbox');
$got = (new SpaceMove($bb))->import($zip);
$nid = (int)$got['id'];
check('move: imported under a free name, code and folder', $got['name'] === 'Acme 2' && $got['code'] !== 'ACM' && $got['folder'] === '/Clients/Acme (2)', json_encode([$got['name'], $got['code'], $got['folder']]));
check('move: its settings came along, not the account', $got['instructions'] === 'Be brief.' && $got['notes'] === 'Alex is the contact.' && $got['privacy'] === 'confidential' && $got['tier'] === 'deep' && $got['account'] === '');
check('move: the history says it moved', ($bb->spaces()->history($nid)[0]['what'] ?? '') === 'moved' && ($bb->spaces()->history($nid)[0]['detail'] ?? '') === 'Work'
    && count($bb->spaces()->history($nid)) >= 2, json_encode($bb->spaces()->history($nid)));
$bs = $bb->store;
$msgs = $bs->q('SELECT * FROM messages WHERE space = ? ORDER BY id', [$nid])->fetchAll();
$q = (int)$bs->q("SELECT id FROM files WHERE name = 'quote.txt'")->fetchColumn();
$p = (int)$bs->q("SELECT id FROM files WHERE name = 'prices.txt'")->fetchColumn();
check('move: the documents are in the new folder, or back where they were', (string)$bs->q('SELECT folder FROM files WHERE id = ?', [$q])->fetchColumn() === '/Clients/Acme (2)/Quotes'
    && (string)$bs->q('SELECT folder FROM files WHERE id = ?', [$p])->fetchColumn() === '/Reference');
check('move: their text came along, nothing to read again', (string)$bs->q('SELECT status FROM files WHERE id = ?', [$q])->fetchColumn() === 'ready'
    && str_contains((string)$bs->q('SELECT text FROM files WHERE id = ?', [$q])->fetchColumn(), '1200 EUR') && file_get_contents($bb->files()->get($q)['path']) === 'Quote for Acme: 1200 EUR');
check('move: the conversation points to the new file ids', count($msgs) === 2 && str_contains($msgs[0]['content'], "#$p: prices.txt") && json_decode($msgs[0]['files'], true) === [$p]
    && str_contains($msgs[1]['content'], "(/files/$q)"), json_encode(array_column($msgs, 'content')));
$newRoutine = (int)$bs->q('SELECT id FROM tasks WHERE space = ?', [$nid])->fetchColumn();
$newJob = (int)$bs->q('SELECT id FROM jobs WHERE space = ?', [$nid])->fetchColumn();
check('move: the routine runs the moved job, the message knows its routine', $newRoutine && (int)$bs->q('SELECT job FROM tasks WHERE id = ?', [$newRoutine])->fetchColumn() === $newJob
    && (json_decode((string)$msgs[1]['meta'], true)['routine'] ?? 0) === $newRoutine);
check('move: the job keeps its report and steps', (int)$bs->q('SELECT report_file FROM jobs WHERE id = ?', [$newJob])->fetchColumn() === $q
    && str_contains((string)$bs->q('SELECT result FROM jobs WHERE id = ?', [$newJob])->fetchColumn(), "/files/$q#p1")
    && (int)$bs->q('SELECT COUNT(*) FROM tool_log WHERE job_id = ?', [$newJob])->fetchColumn() === 1);
check('move: the summary and the summarized position', (int)$bs->q('SELECT COUNT(*) FROM summaries WHERE space = ?', [$nid])->fetchColumn() === 1
    && (int)$bs->get(\Talixio\Store::summarizedKey($nid)) === (int)$msgs[1]['id']);
check('move: the conversation is searchable there', (int)$bs->q("SELECT COUNT(*) FROM docs WHERE kind = 'message' AND space = ?", [$nid])->fetchColumn() === 2);
$cards = $bs->q('SELECT * FROM cards WHERE space = ? ORDER BY id', [$nid])->fetchAll();
$code = $got['code'];
check('move: tasks get the new code, keep the old one, and their text follows', count($cards) === 2 && $cards[0]['code'] === "$code-1" && str_contains($cards[0]['old_codes'], ',ACM-1,')
    && $cards[1]['title'] === "Follow up after $code-1" && str_contains($msgs[1]['content'], 'ACM-1'), json_encode(array_column($cards, 'title')));
check('move: task links and time follow, the calendar event does not', (json_decode($cards[0]['links'], true)[0]['ref'] ?? 0) === $q && str_contains($cards[0]['body'], "/files/$q")
    && $cards[1]['gcal_id'] === null && (int)$bs->q('SELECT COUNT(*) FROM card_deps WHERE card = ? AND depends_on = ?', [$cards[1]['id'], $cards[0]['id']])->fetchColumn() === 1
    && (float)$bs->q('SELECT SUM(hours) FROM card_time WHERE card = ?', [$cards[0]['id']])->fetchColumn() === 1.5);
$c3 = $bb->board()->create(['title' => 'New one'], $nid);
check('move: new tasks number after the moved ones', $c3['code'] === "$code-3", $c3['code']);
check('move: what the Mind learned came along', (int)$bs->q('SELECT COUNT(*) FROM mind_beliefs WHERE space = ?', [$nid])->fetchColumn() === 1
    && (int)$bs->q('SELECT COUNT(*) FROM mind_journal WHERE space = ?', [$nid])->fetchColumn() === 1 && $bs->get("mind_portrait_$nid") === 'Acme portrait');
check('move: the import report', $got['moved']['messages'] === 2 && $got['moved']['files'] === 2 && $got['moved']['code_changed'] && $got['moved']['folder_changed'], json_encode($got['moved']));

// a package moved again into an assistant without the clash keeps its name, code and folder
$cc = $open(test_dir('move-c'), 'home2', 'Other');
$got2 = (new SpaceMove($cc))->import($zip);
check('move: no clash, nothing renamed', $got2['name'] === 'Acme' && $got2['code'] === 'ACM' && $got2['folder'] === '/Clients/Acme' && $cc->board()->find("ACM-1") !== null);

// ---- back on A: removed once B has it
$a = $open($da, 'work', 'Work');
$st = $a->store;
(new SpaceMove($a))->remove($id);
check('move: the space is gone from A', $a->spaces()->get($id) === null && (int)$st->q('SELECT COUNT(*) FROM messages WHERE space = ?', [$id])->fetchColumn() === 0
    && (int)$st->q('SELECT COUNT(*) FROM jobs WHERE space = ?', [$id])->fetchColumn() === 0 && (int)$st->q('SELECT COUNT(*) FROM tasks WHERE space = ?', [$id])->fetchColumn() === 0
    && (int)$st->q("SELECT COUNT(*) FROM docs WHERE kind = 'message' AND space = ?", [$id])->fetchColumn() === 0);
check('move: General keeps its own', (int)$st->q('SELECT COUNT(*) FROM messages WHERE id = ?', [$other])->fetchColumn() === 1 && $a->board()->find($gen["code"]) !== null);
check('move: a task in the calendar leaves it before it is forgotten', (int)$st->q("SELECT in_calendar FROM cards WHERE gcal_id = 'evt1'")->fetchColumn() === 0
    && (int)$st->q("SELECT space FROM cards WHERE gcal_id = 'evt1'")->fetchColumn() === -1 && (int)$st->q('SELECT COUNT(*) FROM cards WHERE space = ?', [$id])->fetchColumn() === 0);
check('move: the folder goes to the trash, documents outside stay', $a->files()->get($inFolder) === null && $a->files()->get($inFolder, true) !== null && $a->files()->get($outside) !== null);
check('move: chat apps write to General again', $a->spaces()->forChannel('telegram') === 0);
check('move: the Mind forgets it here', (int)$st->q('SELECT COUNT(*) FROM mind_beliefs WHERE space = ?', [$id])->fetchColumn() === 0 && $st->get("mind_portrait_$id") === '');
@unlink($zip);

// ---- through the web: export, ticket, receive (another origin), moved; and import from a file
$ka = bin2hex(random_bytes(32)); $kb = bin2hex(random_bytes(32));
$wa = test_dir('move-web-a'); $wb = test_dir('move-web-b');
$webB = web_app($wb, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery', 'TALIXIO_INSTANCE' => 'home', 'TALIXIO_SSO_KEY' => $kb, 'TALIXIO_INSTANCE_NAME' => 'Private',
    'TALIXIO_LINKS' => json_encode([['id' => 'work', 'name' => 'Work', 'url' => 'https://work.acme.example/', 'key' => $ka]])]);
$webA = web_app($wa, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery', 'TALIXIO_INSTANCE' => 'work', 'TALIXIO_SSO_KEY' => $ka, 'TALIXIO_INSTANCE_NAME' => 'Work',
    'TALIXIO_LINKS' => json_encode([['id' => 'home', 'name' => 'Private', 'url' => 'https://home.acme.example/', 'key' => $kb]])]);
$webA('/api/login', ['password' => 'correct horse battery']);
$webB('/api/login', ['password' => 'correct horse battery']);
$csp = $webA('/', null)['_headers']['content-security-policy'] ?? '';
check('move web: the page may send to the linked assistants only', str_contains($csp, "connect-src 'self' https://home.acme.example"), $csp);
$made = $webA('/api/spaces', ['name' => 'Beta', 'folder' => '/Beta', 'code' => 'BET']);
$sid = (int)$made['space']['id'];
$webA('/api/chat', ['text' => 'hello beta', 'space' => $sid]);
$raw = function (string $url, string $body, array $headers, string $method = 'POST') {
    $ch = curl_init($url); $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]
        + ($method === 'POST' ? [CURLOPT_POSTFIELDS => $body] : [])
        + [CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($h); }]);
    $out = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $out, $hdr];
};
$portOf = function (\Closure $web): string { $r = new ReflectionFunction($web); return (string)$r->getStaticVariables()['port']; };
$pa = $portOf($webA); $pb = $portOf($webB);
[$code, $pkg] = $raw("http://127.0.0.1:$pa/api/spaces/$sid/export", '', ['X-Talixio: 1', 'Cookie: ' . preg_replace('/.*\t(\S+)\t(\S+)\s*$/s', '$1=$2', (string)file_get_contents("$wa/ck-a"))], 'GET');
check('move web: export gives a zip', $code === 200 && str_starts_with($pkg, "PK"), (string)$code);
check('move web: export needs a session', $webA("/api/spaces/$sid/export", null, 'nobody')['_code'] === 401);
$tk = $webA("/api/spaces/$sid/move-ticket", ['to' => 'home']);
check('move web: a ticket to receive it on the other', ($tk['url'] ?? '') === 'https://home.acme.example/api/spaces/receive' && strlen($tk['t'] ?? '') > 40 && ($tk['name'] ?? '') === 'Private', $tk['_raw']);
check('move web: no ticket for General or an unknown assistant', $webA('/api/spaces/0/move-ticket', ['to' => 'home'])['_code'] === 400 && $webA("/api/spaces/$sid/move-ticket", ['to' => 'zed'])['_code'] === 400);
check('move web: an import ticket does not open a session', $webB('/sso?t=' . $tk['t'], null, 'sso')['_code'] === 303 && $webB('/api/status', null, 'sso')['_code'] === 401);
$sso = $webA('/api/assistants/go', ['to' => 'home']);
[$code] = $raw("http://127.0.0.1:$pb/api/spaces/receive?" . parse_url($sso['url'] ?? '', PHP_URL_QUERY), $pkg, ['X-Talixio: 1', 'Content-Type: application/zip']);
check('move web: a sign-in ticket cannot push a space', $code === 403, (string)$code);
$tk = $webA("/api/spaces/$sid/move-ticket", ['to' => 'home']);
[$code, , $h] = $raw("http://127.0.0.1:$pb/api/spaces/receive?t=x", '', ['Origin: https://work.acme.example', 'Access-Control-Request-Method: POST'], 'OPTIONS');
check('move web: B lets the linked assistant\'s page send', $code === 204 && ($h['access-control-allow-origin'] ?? '') === 'https://work.acme.example', json_encode($h));
[, , $h] = $raw("http://127.0.0.1:$pb/api/spaces/receive?t=x", '', ['Origin: https://evil.example'], 'OPTIONS');
check('move web: ... and no other site', !isset($h['access-control-allow-origin']));
[$code, $out] = $raw("http://127.0.0.1:$pb/api/spaces/receive?t=bogus", $pkg, ['X-Talixio: 1', 'Content-Type: application/zip']);
check('move web: a bad ticket is refused', $code === 403, "$code $out");
[$code, $out, $h] = $raw("http://127.0.0.1:$pb/api/spaces/receive?t=" . $tk['t'], $pkg, ['X-Talixio: 1', 'Content-Type: application/zip', 'Origin: https://work.acme.example']);
$recv = json_decode($out, true);
check('move web: B receives it', $code === 200 && ($recv['space']['name'] ?? '') === 'Beta' && ($recv['space']['moved']['messages'] ?? 0) >= 1 && ($h['access-control-allow-origin'] ?? '') === 'https://work.acme.example', "$code $out");
[$code] = $raw("http://127.0.0.1:$pb/api/spaces/receive?t=" . $tk['t'], $pkg, ['X-Talixio: 1', 'Content-Type: application/zip']);
check('move web: the ticket works once', $code === 403, (string)$code);
$list = $webB('/api/spaces');
check('move web: B lists it', in_array('Beta', array_column($list['spaces'] ?? [], 'name'), true));
check('move web: A removes it', $webA("/api/spaces/$sid/moved", [])['ok'] ?? false);
check('move web: ... and no longer has it', !in_array('Beta', array_column($webA('/api/spaces')['spaces'] ?? [], 'name'), true));
// import a downloaded package (signed in)
$ck = 'Cookie: ' . preg_replace('/.*\t(\S+)\t(\S+)\s*$/s', '$1=$2', (string)file_get_contents("$wb/ck-a"));
[$code, $out] = $raw("http://127.0.0.1:$pb/api/spaces/import", $pkg, ['X-Talixio: 1', 'Content-Type: application/zip', $ck]);
check('move web: import a file', $code === 200 && (json_decode($out, true)['space']['name'] ?? '') === 'Beta 2', "$code $out");
[$code] = $raw("http://127.0.0.1:$pb/api/spaces/import", $pkg, ['X-Talixio: 1', 'Content-Type: application/zip']);
check('move web: import needs a session', $code === 401, (string)$code);
[$code, $out] = $raw("http://127.0.0.1:$pb/api/spaces/import", 'garbage', ['X-Talixio: 1', 'Content-Type: application/zip', $ck]);
check('move web: import refuses something else', $code === 400, "$code $out");

foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
