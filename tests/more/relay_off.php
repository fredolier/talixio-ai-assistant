<?php
declare(strict_types=1);
// 1.45.0: the cloud relay (the fleet orchestrator, docs/design/relay-retirement.md) is switched off. Its three public routes
// answer 410 to an old orchestrator still running; what the user taught it moves, once, into the director's knowledge;
// conductor.json (a live secret and a webhook) is deleted; the fleet_* tools, the relay triage and its panels go.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, JsonFile, Knowledge, Tools};

$d = test_dir('relay_off');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$notes = fn(string $like) => (int)$app->store->q('SELECT COUNT(*) FROM notifications WHERE title LIKE ? OR body LIKE ?', ["%$like%", "%$like%"])->fetchColumn();

// what an older version left: a paired relay with answered questions and agent threads
$t0 = time() - 86400 * 3;
JsonFile::write("$d/conductor.json", [
    'secret_hash' => hash('sha256', 'old-secret'), 'webhook' => 'https://hooks.example/abc', 'mode' => 'push',
    'agents' => [['id' => 'ag-1', 'name' => 'webshop', 'machine' => 'laptop', 'state' => 'idle', 'ts' => $t0]],
    'decisions' => [
        ['id' => 'd-1', 'ts' => $t0, 'agent' => 'ag-1', 'name' => 'webshop', 'kind' => 'decision', 'type' => 'L2', 'question' => 'Shall I add a test for the coupon code?', 'answer' => 'Yes, always test first.', 'by' => 'user'],
        ['id' => 'd-2', 'ts' => $t0 + 60, 'agent' => 'ag-1', 'name' => 'webshop', 'kind' => 'decision', 'type' => 'L1', 'question' => 'Go on with the footer?', 'answer' => 'Yes.', 'by' => 'assistant'],
    ],
    'threads' => ['ag-1' => [
        ['role' => 'agent', 'text' => 'I fixed the coupon total. Shall I add a test for the coupon code?', 'ts' => $t0],
        ['role' => 'user', 'text' => 'Yes, always test first.', 'ts' => $t0 + 30],
    ]],
    'reports' => [['ts' => $t0, 'kind' => 'summary', 'title' => 'Pass 12', 'text' => 'Nothing new.']],
], 0600);
file_put_contents("$d/pilot-status.md", "# old\n");

$app->pilot()->tick(time());
$dec = $app->knowledge()->decisions('', '', '', 50);
$relay = array_values(array_filter($dec, fn($x) => $x['source'] === 'relay'));
check('relay off: the relay\'s answered questions move into the decision log, with who answered', count($relay) === 2
    && in_array('you', array_column($relay, 'by'), true) && in_array('assistant', array_column($relay, 'by'), true)
    && ($relay[1]['ts'] ?? 0) === $t0 && ($relay[1]['level'] ?? '') === 'L2' && ($relay[1]['topic'] ?? '') === 'tests', json_encode($relay));
$dg = $app->store->q("SELECT * FROM fk_digests WHERE source = 'relay'")->fetchAll();
check('relay off: each agent\'s thread is kept as a note of its project, marked as imported', count($dg) === 1 && str_contains($dg[0]['text'], 'coupon total')
    && str_contains($dg[0]['text'], 'cloud orchestrator') && $dg[0]['project'] === Knowledge::projectKey('relay', 'webshop'), json_encode($dg));
check('relay off: the project is named after the agent', ($app->knowledge()->project(Knowledge::projectKey('relay', 'webshop'))['title'] ?? '') === 'webshop');
check('relay off: conductor.json (a secret and a webhook) is deleted', !is_file("$d/conductor.json"));
check('relay off: the old status file goes too', !is_file("$d/pilot-status.md"));
check('relay off: the user is told once', $notes('orchestrator') === 1);
$app->pilot()->tick(time() + 60);
check('relay off: it happens once', $notes('orchestrator') === 1 && count(array_filter($app->knowledge()->decisions('', '', '', 50), fn($x) => $x['source'] === 'relay')) === 2);
$prec = $app->pilot()->precedents('Shall I add a test for the login?', 3);
check('relay off: what the user answered through the relay still guides the board', in_array('Yes, always test first.', array_column($prec, 'a'), true), json_encode($prec));
check('relay off: …but not what the assistant answered by itself', !in_array('Yes.', array_column($prec, 'a'), true));

// the tools and the triage
$T = $app->tools->forAnswer();
$names = $T->names();
check('relay off: no fleet_* tool is left', !array_filter($names, fn($n) => str_starts_with($n, 'fleet_')), implode(',', array_filter($names, fn($n) => str_starts_with($n, 'fleet_'))));
check('relay off: the local code sessions stay (conductor_dispatch)', in_array('conductor_dispatch', $names, true));
check('relay off: no fleet_* name is left in the tool lists', !array_filter(array_merge(Tools::READS, Tools::EFFECTS), fn($n) => str_starts_with($n, 'fleet_')));
check('relay off: the relay triage is gone', !method_exists(\Talixio\Pilot::class, 'triagePrompt') && !method_exists(\Talixio\Pilot::class, 'decide'));

// the routes: an old orchestrator is told, in words a model reads, to stop; the user hears of it once a day
$dw = "$d/w"; @mkdir($dw, 0777, true);
$web = web_app($dw, ['TALIXIO_WEB_PASSWORD' => 'a-long-password-123']);
foreach ([['/api/conductor/inbox', ['type' => 'report', 'text' => 'hi']], ['/api/conductor/tick', ['agents' => []]], ['/api/conductor/pull', null]] as [$path, $body]) {
    $r = $web($path, $body, 'anon', '', ['Authorization: Bearer old-secret']);
    check("relay off: $path answers 410 with what to do", $r['_code'] === 410 && ($r['error'] ?? '') === 'retired' && str_contains($r['message'] ?? '', 'Connect a machine'), $r['_code'] . ' ' . $r['_raw']);
}
$wapp = (function () use ($dw) { putenv("TALIXIO_DATA_DIR=$dw"); return App::fromEnv(); })();
check('relay off: an old orchestrator still calling is reported to the user once a day', (int)$wapp->store->q("SELECT COUNT(*) FROM notifications WHERE title LIKE '%orchestrator%'")->fetchColumn() === 1);
check('relay off: …and nothing is written for it', !is_file("$dw/conductor.json"));
check('web: signing in works', ($web('/api/login', ['password' => 'a-long-password-123'])['ok'] ?? false) === true);
foreach (['/api/conductor', '/api/conductor/setup'] as $path) {
    $r = $web($path, $path === '/api/conductor' ? null : []);
    check("relay off: the cockpit route $path is gone", $r['_code'] === 404, $r['_code'] . ' ' . mb_substr($r['_raw'], 0, 200));
}
check('relay off: /api/status no longer reports the relay\'s unread count', !array_key_exists('fleet_unread', $web('/api/status')));

foreach ($saved as $kk => $v) putenv($v === false ? $kk : "$kk=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
