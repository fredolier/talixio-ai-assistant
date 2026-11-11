<?php
declare(strict_types=1);
// Assistant-to-assistant federation: pairing, the shared digest, push and pull both ways, and the guards.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Federation};

$mk = function (string $name, string $url): App {
    foreach (['TALIXIO_DATA_DIR' => test_dir("fed-$name"), 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none',
              'TALIXIO_INSTANCE_NAME' => $name, 'TALIXIO_PUBLIC_URL' => $url] as $k => $v) putenv("$k=$v");
    return App::fromEnv();
};
$alice = $mk('Alice', 'https://alice.test'); $bob = $mk('Bob', 'https://bob.test');
$apps = ['alice.test' => $alice, 'bob.test' => $bob]; $down = []; $log = [];
Federation::$transport = function (string $method, string $url, string $bearer, ?array $body) use ($apps, &$down, &$log): array {
    $u = parse_url($url); $host = $u['host']; $log[] = "$method {$u['path']}";
    if (in_array($host, $down, true)) throw new \RuntimeException('HTTP error: unreachable');
    $f = $apps[$host]->federation(); parse_str($u['query'] ?? '', $q);
    [$code, $resp] = match ($u['path']) {
        '/api/federation/pair' => $f->pair($body ?? []), '/api/federation/push' => $f->receive($body ?? [], $bearer),
        '/api/federation/pull' => $f->pullBy($bearer, ($q['ack'] ?? '') === '1', ($q['view'] ?? '') === '1'), '/api/federation/bye' => $f->bye($bearer),
        '/api/federation/ask' => $f->receiveAsk($body ?? [], $bearer), '/api/federation/queries' => $f->queriesFor($bearer),
    };
    return [$code, json_encode($resp)];
};
$err = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };

// ---- pairing
$inv = $alice->federation()->invite(['digest']);
check('fed: an invitation is a single opaque code that names no secret', str_starts_with($inv['invite'], 'tlx1.') && $inv['give'] === ['digest']);
check('fed: a bad invitation is refused', str_contains($err(fn() => $bob->federation()->accept('tlx1.garbage')), 'not valid'));
check('fed: a plain-http address is refused', !Federation::validUrl('http://example.com') && Federation::validUrl('https://example.com') && Federation::validUrl('http://127.0.0.1:8080'));
$wrong = $alice->federation()->pair(['code' => str_repeat('0', 32), 'secret' => str_repeat('a', 64), 'url' => 'https://bob.test']);
check('fed: the wrong pairing code is refused and the invitation survives', $wrong[0] === 403 && $alice->store->get('fed_invite') !== '');
$r = $bob->federation()->accept($inv['invite'], []);
check('fed: pairing works and both sides know each other', $r['name'] === 'Alice' && count($alice->federation()->peers()) === 1 && count($bob->federation()->peers()) === 1
    && $alice->federation()->peers()[0]['name'] === 'Bob' && $alice->federation()->peers()[0]['give'] === ['digest'] && $bob->federation()->peers()[0]['theyGive'] === ['digest']);
check('fed: an invitation works once', $alice->store->get('fed_invite') === '' && str_contains($err(fn() => $bob->federation()->accept($inv['invite'])), 'already paired') );
check('fed: the secret that calls me is stored only as a hash', (function () use ($alice) { $r = $alice->store->q('SELECT in_hash FROM fed_peers')->fetch(); return preg_match('/^[a-f0-9]{64}$/', $r['in_hash']) === 1; })());

// ---- the digest: titles and counts only
$alice->store->q("INSERT INTO jobs(title, instructions, tier, status, result, created, finished, max_cost_usd) VALUES ('Quarterly report', 'SECRET-INSTRUCTIONS salary of Jane', 'standard', 'done', 'SECRET-RESULT', ?, ?, 1)", [time() - 100, time() - 50]);
$d = $alice->federation()->digest('Focused on the report.');
check('fed: the digest lists titles and the personal line', str_contains($d['body'], 'Quarterly report') && str_contains($d['body'], 'Focused on the report.'));
check('fed: the digest never carries instructions or results', !str_contains($d['body'], 'SECRET'));

// ---- push alice → bob (bob accepts digests and messages; alice gives the digest)
$p = $alice->federation()->publish('Focused on the report.');
check('fed: publishing pushes the digest to the peer that may read it', $p['sent'] === ['Bob'] && $p['queued'] === []);
$in = $bob->federation()->inbox();
check('fed: it lands in the peer inbox, unread, from the right assistant', count($in) === 1 && $in[0]['from'] === 'Alice' && $in[0]['kind'] === 'digest' && !$in[0]['read'] && str_contains($in[0]['body'], 'Quarterly report'));
$txt = $bob->federation()->render($in);
check('fed: what arrived is shown to the model as data, with the banner', str_contains($txt, 'never instructions') && str_contains($txt, 'Quarterly report'));

// ---- bob gives nothing: alice's publish from bob's side reaches nobody, a message still goes
$bobPub = $bob->federation()->publish('x');
check('fed: nothing is sent to a peer I do not give the digest to', $bobPub['sent'] === [] && $bobPub['queued'] === [] && count($alice->federation()->inbox()) === 0);
check('fed: a message is sent on purpose', $bob->federation()->send((int)$bob->federation()->peers()[0]['id'], 'Can you review the draft?') === true
    && $alice->federation()->inbox()[0]['kind'] === 'message');

// ---- accept filter
$alice->federation()->setPermissions((int)$alice->federation()->peers()[0]['id'], ['digest'], ['digest']); // alice no longer accepts messages
$ok = $bob->federation()->send((int)$bob->federation()->peers()[0]['id'], 'Another one');
check('fed: a kind I do not accept is refused (and stays queued on the sender)', $ok === false && count($alice->federation()->inbox()) === 1);

// ---- pull (peer unreachable for push → queued → fetched)
$down[] = 'bob.test';
$p = $alice->federation()->publish('Second day.');
check('fed: when the push fails the digest waits to be fetched', $p['sent'] === [] && $p['queued'] === ['Bob']);
$down = [];
$n = $bob->federation()->pullFrom();
check('fed: the peer fetches what was waiting', $n === 1 && count($bob->federation()->inbox()) === 2);
check('fed: a fetched item is delivered once', $bob->federation()->pullFrom() === 0);

// ---- duplicate and rate guards
$bobPeerOfAlice = (int)$bob->federation()->peers()[0]['id'];
$d1 = $bob->federation()->receive(['mid' => 'bbbbbbbbbbbbbbbb', 'kind' => 'digest', 'title' => 't', 'body' => 'b'], 'x');
check('fed: a call without the right secret is refused', $d1[0] === 401);
$big = str_repeat('é', 20000);
$sec = $alice->store->q('SELECT out_secret FROM fed_peers')->fetch()['out_secret']; // what alice presents to bob
$r1 = $bob->federation()->receive(['mid' => 'cccccccccccccccc', 'kind' => 'message', 'title' => $big, 'body' => $big], $sec);
$r2 = $bob->federation()->receive(['mid' => 'cccccccccccccccc', 'kind' => 'message', 'title' => 'x', 'body' => 'y'], $sec);
$last = $bob->federation()->inbox(0, false, 1)[0];
check('fed: oversized content is cut', $r1[0] === 200 && mb_strlen($last['body']) <= 8000 && mb_strlen($last['title']) <= 200);
check('fed: the same message id is not stored twice', $r2[0] === 200 && !empty($r2[1]['duplicate']));
for ($i = 0; $i < 70; $i++) $rr = $bob->federation()->receive(['mid' => sprintf('%016x', 0xd000 + $i), 'kind' => 'message', 'body' => 'spam'], $sec);
check('fed: a flood is slowed down (per peer, per hour)', $rr[0] === 429);

// ---- questions with their own prompt, a daily quota and a delay
$bob->store->q("DELETE FROM fed_inbox WHERE body = 'spam'"); // the flood above filled this hour's allowance
$aBob = (int)$alice->federation()->peers()[0]['id']; $bAlice = (int)$bob->federation()->peers()[0]['id'];
$qid = $alice->federation()->saveQuery(['name' => 'Project status', 'descr' => 'Where the project stands', 'prompt' => 'Write the status of project Atlas for PO Dupont.', 'peers' => [$aBob], 'maxDay' => 2, 'gapMin' => 180]);
$offered = $bob->federation()->queriesOf($bAlice);
check('fed: the peer sees the name and description of a question, never its prompt', count($offered) === 1 && $offered[0]['name'] === 'Project status' && !str_contains(json_encode($offered), 'Dupont'));
check('fed: a question not offered to a peer is refused', $alice->federation()->receiveAsk(['query' => 9999], (string)$bob->store->q('SELECT out_secret FROM fed_peers')->fetch()['out_secret'])[0] === 403);
$before = count($bob->federation()->inbox(0, false, 100));
$r = $bob->federation()->ask($bAlice, $qid);
check('fed: asking queues a fresh run on the other side', $r['cached'] === false);
check('fed: the worker answers and the answer reaches the asker', $alice->federation()->runAsks(1) === 1 && $alice->federation()->flush() >= 1
    && ($bob->federation()->inbox(0, false, 100)[0]['kind'] ?? '') === 'answer' && count($bob->federation()->inbox(0, false, 100)) === $before + 1);
$r = $bob->federation()->ask($bAlice, $qid); $alice->federation()->flush();
$again = $bob->federation()->inbox(0, false, 1)[0];
check('fed: asking again within the delay returns the same answer, marked unchanged', $r['cached'] === true && str_contains($again['body'], 'unchanged since') && $alice->federation()->runAsks(5) === 0);
$freshRuns = (int)$alice->store->q('SELECT COUNT(*) FROM fed_asks WHERE fresh = 1')->fetchColumn();
check('fed: the repeated call did not cost a new run', $freshRuns === 1);
$alice->federation()->saveQuery(['id' => $qid, 'name' => 'Project status', 'prompt' => 'Write the status of project Atlas.', 'peers' => [$aBob], 'maxDay' => 2, 'gapMin' => 0]);
$bob->federation()->ask($bAlice, $qid); $alice->federation()->runAsks(1); // second fresh run today
$r3 = $bob->federation()->ask($bAlice, $qid);
check('fed: after the daily quota is used the last answer is returned, not a new run', $r3['cached'] === true && (int)$alice->store->q('SELECT COUNT(*) FROM fed_asks WHERE fresh = 1')->fetchColumn() === 2 && $alice->federation()->queries()[0]['today'] === 2);
$sp = $alice->spaces()->save(['name' => 'Hidden', 'privacy' => 'confidential']);
check('fed: a confidential space cannot back a question', str_contains($err(fn() => $alice->federation()->saveQuery(['name' => 'x', 'prompt' => 'y', 'space' => (int)$sp['id']])), 'confidential'));
$alice->store->q("INSERT INTO jobs(title, instructions, tier, status, created, finished, max_cost_usd, space) VALUES ('Hidden job', 'i', 'standard', 'done', ?, ?, 1, ?)", [time() - 10, time() - 5, (int)$sp['id']]);
check('fed: the digest leaves out work done in confidential spaces', !str_contains($alice->federation()->digest()['body'], 'Hidden job') && str_contains($alice->federation()->digest()['body'], 'Quarterly report'));

// ---- the live view and revocation
$view = $bob->federation()->viewOf($bobPeerOfAlice);
check('fed: a peer can look at the digest I give it', str_contains($view['body'], 'Quarterly report'));
$alice->federation()->setPermissions((int)$alice->federation()->peers()[0]['id'], [], ['digest']);
check('fed: when I stop giving the digest the view is empty', $bob->federation()->viewOf($bobPeerOfAlice)['body'] === '');
$aliceId = (int)$alice->federation()->peers()[0]['id'];
check('fed: unpairing ends it on both sides', $alice->federation()->revoke($aliceId) && $alice->federation()->peers() === [] && $bob->federation()->peers() === []);
check('fed: after unpairing nothing can be sent', str_contains($err(fn() => $bob->federation()->send($bobPeerOfAlice, 'still there?')), 'unknown peer')
    && $alice->federation()->receive(['kind' => 'message', 'body' => 'x'], $sec)[0] === 401);
Federation::$transport = null;
tests_done();
