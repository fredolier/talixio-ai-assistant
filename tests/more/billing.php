<?php
declare(strict_types=1);
// Real costs read from the providers' billing APIs (Admin keys), replacing the estimates of the days already billed.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Budget};

$d = test_dir('billing');
$fakeDir = "$d/fake"; @mkdir($fakeDir);
file_put_contents("$fakeDir/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
file_put_contents("$dir/log.jsonl", json_encode(['path' => $path, 'q' => $q, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '', 'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '',
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '']) . "\n", FILE_APPEND | LOCK_EX);
$routes = json_decode((string)@file_get_contents("$dir/routes.json"), true) ?: [];
$hit = $routes[$path . (isset($q['page']) ? '?page=' . $q['page'] : '')] ?? [404, ['error' => ['message' => 'no route']]];
http_response_code($hit[0]);
header('Content-Type: application/json');
echo json_encode($hit[1]);
PHP);
$port = free_port();
$fenv = ['PATH' => getenv('PATH')] + (getenv('PHP_INI_SCAN_DIR') !== false ? ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')] : []);
$fp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$fakeDir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fpp, null, $fenv);
register_shutdown_function(function () use ($fp) { proc_terminate($fp); proc_close($fp); });
wait_port($port);
$U = "http://127.0.0.1:$port";
$routes = function (array $r) use ($fakeDir) { file_put_contents("$fakeDir/routes.json", json_encode($r)); };
$calls = function () use ($fakeDir): array { return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("$fakeDir/log.jsonl")))); };
$clear = function () use ($fakeDir) { @unlink("$fakeDir/log.jsonl"); };

$now = time(); $D = intdiv($now, 86400) * 86400; // today, UTC
$day = fn(int $i) => gmdate('Y-m-d', $D - $i * 86400);
$iso = fn(int $i) => gmdate('Y-m-d\TH:i:s\Z', $D - $i * 86400);
$routes([
    '/v1/organizations/cost_report' => [200, ['data' => [
        ['starting_at' => $iso(3), 'results' => [['amount' => '150.5', 'currency' => 'USD', 'workspace_id' => 'wrkspc_a'], ['amount' => '9999', 'currency' => 'USD', 'workspace_id' => 'wrkspc_b']]],
        ['starting_at' => $iso(2), 'results' => [['amount' => '100', 'currency' => 'USD', 'workspace_id' => 'wrkspc_a']]],
    ], 'has_more' => true, 'next_page' => 'p2']],
    '/v1/organizations/cost_report?page=p2' => [200, ['data' => [
        ['starting_at' => $iso(1), 'results' => [['amount' => '200', 'currency' => 'USD', 'workspace_id' => 'wrkspc_a']]],
        ['starting_at' => $iso(0), 'results' => [['amount' => '5000', 'currency' => 'USD', 'workspace_id' => 'wrkspc_a']]],
    ], 'has_more' => false, 'next_page' => null]],
    '/v1/organization/costs' => [200, ['data' => [
        ['start_time' => $D - 2 * 86400, 'results' => [['amount' => ['value' => 0.5, 'currency' => 'usd'], 'project_id' => 'proj_1'], ['amount' => ['value' => 7, 'currency' => 'usd'], 'project_id' => 'proj_2']]],
    ], 'has_more' => false]],
]);

$keep = [];
$env = ['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => 'sk-ant-api-test', 'ANTHROPIC_BASE_URL' => $U,
    'ANTHROPIC_ADMIN_KEY' => 'sk-ant-admin-secret', 'ANTHROPIC_WORKSPACE_ID' => 'wrkspc_a',
    'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_TRANSCRIBE' => 'off', 'TALIXIO_SEARCH' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_MIND' => '0'];
foreach ($env as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv();
$st = $app->store;
$b = $app->billing();

// ---- estimates, tagged with the settings that made them
$use = function (int $i, float $cost, string $account = '') use ($st, $D) {
    $st->addUsage(['model' => 'claude', 'in' => 10, 'out' => 10], $cost, 'chat', 'standard', null, $account);
    $st->q('UPDATE usage SET ts = ? WHERE id = (SELECT MAX(id) FROM usage)', [$D - $i * 86400 + 3600]);
};
$use(3, 1.0); $use(2, 1.0); $use(1, 1.0); $use(0, 2.0); $use(2, 0.4, 'acc-oa'); $use(1, 5.0, '-');
check('billing: usage is tagged per account', abs($st->spentBetween($D - 3 * 86400, $D + 86400, '') - 5.0) < 1e-9 && abs($st->spentBetween($D - 3 * 86400, $D, 'acc-oa') - 0.4) < 1e-9
    && abs($st->spentBetween($D - 3 * 86400, $D + 86400) - 10.4) < 1e-9);

// ---- the main settings: Anthropic, in cents, one workspace, two pages
check('billing: the main Anthropic settings with an Admin key are a source', $b->coversMain() && $b->sources()['main']['scope'] === 'wrkspc_a');
$clear();
$st0 = $b->sync(true, $now);
$data = $b->data()['main'];
check('billing: Anthropic cents read, other workspaces left out, both pages', abs($data['days'][$day(3)] - 1.505) < 1e-9 && abs($data['days'][$day(2)] - 1.0) < 1e-9
    && abs($data['days'][$day(1)] - 2.0) < 1e-9 && $data['error'] === '', json_encode($data));
$c = $calls();
check('billing: Admin key and API version sent, the next page asked for', count($c) === 2 && $c[0]['key'] === 'sk-ant-admin-secret' && $c[0]['version'] === '2023-06-01'
    && ($c[1]['q']['page'] ?? '') === 'p2' && $c[0]['q']['bucket_width'] === '1d', json_encode($c));
check('billing: the status never carries a key', !str_contains(json_encode($st0), 'sk-ant') && $st0['sources'][0]['name'] === 'Anthropic');

// ---- correction: real / estimate over complete days (today is left out)
$f = $b->factor($now);
check('billing: factor from complete days only', $f !== null && abs($f - (1.505 + 1.0 + 2.0) / 3.0) < 1e-6, (string)$f);
[$rep, $real] = $b->adjust($D - 2 * 86400, $D + 86400, $now);
check('billing: adjust replaces the billed days\' estimates', abs($rep - 2.0) < 1e-9 && abs($real - 3.0) < 1e-9, "$rep $real");
$bud = new Budget($st, 0, 0, 1.5, fn($from, $to) => [2.0, 3.0]);
check('billing: the budget counts real costs plus corrected estimates', abs($bud->status()['month'] - (max(0, $st->spentSince(strtotime('first day of this month 00:00')) - 2.0) * 1.5 + 3.0)) < 1e-9);
check('billing: the calibration uses it and asks for no bill', abs($app->calibration()->factor() - $f) < 1e-9 && $app->calibration()->due($now) === null
    && str_contains($app->calibration()->describe(), 'really billed'));
check('billing: the usage report mentions it', str_contains($app->tools->run('usage_report', []), 'Real costs read from the provider'));

// ---- at most every 6 hours, unless asked
$clear();
$b->sync(false, $now + 3600);
check('billing: no new call within 6 hours', $calls() === []);
$b->sync(false, $now + 7 * 3600);
check('billing: read again after 6 hours', count($calls()) === 2);

// ---- an OpenAI account, one project; the Admin key is never sent back
$acc = $app->accounts()->save(['name' => 'OpenAI Pro', 'kind' => 'openai', 'url' => "$U/v1", 'key' => 'sk-api', 'admin_key' => 'sk-admin-oa', 'billing_scope' => 'proj_1']);
$id = $acc['id'];
$st->q("UPDATE usage SET account = ? WHERE account = 'acc-oa'", [$id]);
check('billing: an account\'s Admin key stays on the server', !isset($acc['admin_key']) && $acc['has_admin_key'] && !str_contains(json_encode($app->accounts()->all()), 'sk-admin-oa'));
$clear();
$b->sync(true, $now);
$oa = $b->data()[$id] ?? [];
$c = array_values(array_filter($calls(), fn($x) => $x['path'] === '/v1/organization/costs'));
check('billing: OpenAI per project, Bearer Admin key', abs(($oa['days'][$day(2)] ?? 0) - 0.5) < 1e-9 && ($c[0]['auth'] ?? '') === 'Bearer sk-admin-oa' && ($c[0]['q']['start_time'] ?? '') !== '', json_encode($oa));
[$rep2, $real2] = $b->adjust($D - 2 * 86400, $D - 86400, $now);
check('billing: each source against its own estimates', abs($rep2 - 1.4) < 1e-9 && abs($real2 - 1.5) < 1e-9, "$rep2 $real2");
$app->accounts()->save(['name' => 'OpenAI Pro', 'kind' => 'openai', 'url' => "$U/v1", 'billing_scope' => 'proj_1'], $id);
check('billing: an Admin key left empty is kept', $app->accounts()->get($id, true)['admin_key'] === 'sk-admin-oa');
$app->accounts()->save(['name' => 'OpenAI Pro', 'kind' => 'openai', 'url' => "$U/v1", 'admin_clear' => true], $id);
check('billing: ... and can be forgotten', $app->accounts()->get($id, true)['admin_key'] === '' && !isset($b->sources()[$id]));
check('billing: not for other kinds', $app->accounts()->save(['name' => 'Gem', 'kind' => 'gemini', 'key' => 'g', 'admin_key' => 'x'])['has_admin_key'] === false);

// ---- errors: kept with what was read before
$routes(['/v1/organizations/cost_report' => [401, ['error' => ['message' => 'invalid x-api-key']]]]);
$s = $b->sync(true, $now);
$main = $b->data()['main'];
check('billing: a refused key says so and keeps the days read', str_contains($main['error'], 'Admin key was refused') && count($main['days']) >= 3 && $s['sources'][0]['error'] !== '');
check('billing: the Activity page shows it', str_contains(json_encode((new ReflectionMethod(Talixio\Web::class, 'activity'))->invoke(new Talixio\Web($app))['billing']), 'refused'));

// ---- without an Admin key: the estimates and the reported bills, as before
putenv('ANTHROPIC_ADMIN_KEY');
$app2 = App::fromEnv();
check('billing: no Admin key, no source', !$app2->billing()->coversMain() && $app2->billing()->sources() === [] && $app2->billing()->describe() === '');

foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
