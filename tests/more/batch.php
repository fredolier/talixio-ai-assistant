<?php
declare(strict_types=1);
// Mistral batch API: summaries and the Mind's background work at half price, answers collected later by the worker.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Batch, Summarizer};

$d = test_dir('batch');
$fakeDir = "$d/fake"; @mkdir($fakeDir);
file_put_contents("$fakeDir/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$multi = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart');
$body = $multi ? json_encode(['purpose' => $_POST['purpose'] ?? '', 'file' => isset($_FILES['file']) ? file_get_contents($_FILES['file']['tmp_name']) : '']) : file_get_contents('php://input');
file_put_contents("$dir/log.jsonl", json_encode(['m' => $m, 'path' => $path, 'body' => $body, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '']) . "\n", FILE_APPEND | LOCK_EX);
$routes = json_decode((string)@file_get_contents("$dir/routes.json"), true) ?: [];
$hit = $routes["$m $path"] ?? [404, ['error' => 'no route']];
http_response_code($hit[0]);
header('Content-Type: application/json');
echo is_string($hit[1]) ? $hit[1] : json_encode($hit[1]);
PHP);
$port = free_port();
$fenv = ['PATH' => getenv('PATH')] + (getenv('PHP_INI_SCAN_DIR') !== false ? ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')] : []);
$fp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$fakeDir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fpp, null, $fenv);
register_shutdown_function(function () use ($fp) { proc_terminate($fp); proc_close($fp); });
wait_port($port);
$U = "http://127.0.0.1:$port/v1";
$base = [
    'GET /v1/models' => [200, ['data' => [['id' => 'mistral-small-latest'], ['id' => 'mistral-large-latest']]]],
    'POST /v1/chat/completions' => [200, ['choices' => [['message' => ['content' => 'Direct answer.']]], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]]],
    'POST /v1/files' => [200, ['id' => 'file-in']],
    'POST /v1/batch/jobs' => [200, ['id' => 'job-1', 'status' => 'QUEUED']],
    'GET /v1/batch/jobs/job-1' => [200, ['id' => 'job-1', 'status' => 'RUNNING']],
];
$routes = function (array $r) use ($fakeDir, $base) { file_put_contents("$fakeDir/routes.json", json_encode($r + $base)); };
$clear = function () use ($fakeDir) { @unlink("$fakeDir/log.jsonl"); };
$calls = function (string $path = '') use ($fakeDir): array {
    $all = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("$fakeDir/log.jsonl"))));
    return array_values(array_filter($all, fn($c) => $path === '' || $c['m'] . ' ' . $c['path'] === $path));
};
$output = fn(string $text, int $in = 2000, int $out = 200) => json_encode(['id' => 'r', 'custom_id' => '0',
    'response' => ['status_code' => 200, 'body' => ['choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]]], 'error' => null]) . "\n";

$keep = [];
$env = ['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'sovereign', 'TALIXIO_SOVEREIGN' => 'mistral', 'TALIXIO_SOVEREIGN_URL' => $U, 'TALIXIO_SOVEREIGN_KEY' => 'eu-key',
    'TALIXIO_MODEL_FAST' => 'mistral-small-latest', 'TALIXIO_MODEL' => 'mistral-small-latest', 'TALIXIO_MODEL_DEEP' => 'mistral-large-latest',
    'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_TRANSCRIBE' => 'off', 'TALIXIO_SEARCH' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_MIND' => '0', 'TALIXIO_PRICES' => 'mistral-small=1/10'];
foreach ($env as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$routes([]);
$app = App::fromEnv();
$st = $app->store;
check('batch: on by default with a Mistral account', $app->cfg->mistralBatch && (new Batch($app))->eligible());
check('batch: off when asked now', !(new Batch($app, true))->eligible());

// ---- a stretch of conversation: sent to the batch API, collected later
$t0 = time() - 7200;
for ($i = 0; $i < 30; $i++) $st->addMessage($i % 2 ? 'assistant' : 'user', 'web', "Message $i about the Acme project");
$st->q('UPDATE messages SET ts = ?', [$t0]);
$clear();
$sum = new Summarizer($app);
check('batch: the summary is not there yet', $sum->maybeRun(time()) === false && (int)$st->q('SELECT COUNT(*) FROM summaries')->fetchColumn() === 0);
$up = $calls('POST /v1/files');
$job = $calls('POST /v1/batch/jobs');
$line = json_decode(json_decode($up[0]['body'] ?? '{}', true)['file'] ?? '', true) ?: [];
check('batch: the request is uploaded as a JSONL file', count($up) === 1 && json_decode($up[0]['body'], true)['purpose'] === 'batch' && $up[0]['auth'] === 'Bearer eu-key'
    && ($line['custom_id'] ?? '') === '0' && ($line['body']['messages'][0]['role'] ?? '') === 'system' && str_contains($line['body']['messages'][1]['content'] ?? '', 'Message 0 about the Acme project'), json_encode($up));
$jb = json_decode($job[0]['body'] ?? '{}', true);
check('batch: a job is created with the fast model', count($job) === 1 && $jb['input_files'] === ['file-in'] && $jb['model'] === 'mistral-small-latest' && $jb['endpoint'] === '/v1/chat/completions');
check('batch: no direct call was made', $calls('POST /v1/chat/completions') === []);
$clear();
$sum->maybeRun(time());
check('batch: asked again while it runs, nothing is sent twice', $calls('POST /v1/files') === [] && $calls('POST /v1/chat/completions') === [] && (new Batch($app))->waiting() === 1);

for ($i = 0; $i < 5; $i++) $st->addMessage('user', 'web', "Later message $i"); // new messages meanwhile: the stretch sent stays the same
$b = new Batch($app);
check('batch: the worker waits while it runs', $b->poll(time()) === 0 && (new Batch($app))->waiting() === 1);
$routes(['GET /v1/batch/jobs/job-1' => [200, ['id' => 'job-1', 'status' => 'SUCCESS', 'output_file' => 'file-out']], 'GET /v1/files/file-out/content' => [200, $output('- Acme: decided to ship in March.')]]);
$st->q('UPDATE batches SET checked = 0');
$spent = (float)$st->q('SELECT COALESCE(SUM(cost_usd),0) FROM usage')->fetchColumn();
check('batch: the worker collects the answer', $b->poll(time()) === 1);
$u = $st->q('SELECT * FROM usage ORDER BY id DESC LIMIT 1')->fetch();
check('batch: its usage is counted at half price', $u['model'] === 'mistral-small-latest' && (int)$u['input_tokens'] === 2000 && abs((float)$u['cost_usd'] - (2000 * 1 + 200 * 10) / 1e6 / 2) < 1e-9
    && $u['source'] === 'summary (batch)' && $u['tier'] === 'fast', json_encode($u));
$sum->maybeRun(time());
$s = $st->q('SELECT * FROM summaries')->fetchAll();
$first = (int)$st->q('SELECT MIN(id) FROM messages')->fetchColumn();
check('batch: the summary is saved for the stretch that was sent', count($s) === 1 && $s[0]['text'] === '- Acme: decided to ship in March.'
    && (int)$s[0]['from_id'] === $first && (int)$s[0]['to_id'] === $first + 19 && (int)$st->get('summarized_upto', $st->get(\Talixio\Store::summarizedKey(0))) === $first + 19, json_encode($s));
check('batch: the request is forgotten once used', (int)$st->q('SELECT COUNT(*) FROM batches')->fetchColumn() === 0);

// ---- a batch that fails: done directly
$routes(['GET /v1/batch/jobs/job-1' => [200, ['id' => 'job-1', 'status' => 'FAILED']]]);
for ($i = 0; $i < 20; $i++) $st->addMessage('user', 'web', "More $i");
$st->q('UPDATE messages SET ts = ?', [$t0]);
$sum->maybeRun(time());
$st->q('UPDATE batches SET checked = 0');
(new Batch($app))->poll(time());
check('batch: a failed job is marked', $st->q('SELECT status FROM batches')->fetchColumn() === 'failed');
$clear();
$sum->maybeRun(time());
check('batch: a failed job is made again directly', count($calls('POST /v1/chat/completions')) === 1 && $st->q("SELECT text FROM summaries ORDER BY id DESC LIMIT 1")->fetchColumn() === 'Direct answer.');

// ---- asked now (Memory → Compact now): never waits for a batch
for ($i = 0; $i < 20; $i++) $st->addMessage('user', 'web', "Now $i");
$routes(['POST /v1/batch/jobs/job-1/cancel' => [200, ['status' => 'CANCELLATION_REQUESTED']]]);
$sum->maybeRun(time() + 7200);
check('batch: a background summary is waiting', (new Batch($app))->waiting() === 1);
$clear();
$now = new Summarizer($app, true);
check('batch: compacting now answers at once', $now->compact(time(), 1, 0, true) === true && count($calls('POST /v1/chat/completions')) === 1
    && count($calls('POST /v1/batch/jobs/job-1/cancel')) === 1 && (new Batch($app))->waiting() === 0);

// ---- not eligible: setting off, budget low, another provider
putenv('TALIXIO_MISTRAL_BATCH=0');
$off = App::fromEnv();
check('batch: the setting turns it off', !$off->cfg->mistralBatch && !(new Batch($off))->eligible());
$clear();
[$state, $text] = (new Batch($off))->ask('test:1', 'summary', 'summary', 'fast', 'Say hi.');
check('batch: turned off, the call is direct', $state === 'done' && $text === 'Direct answer.' && count($calls('POST /v1/chat/completions')) === 1 && $calls('POST /v1/files') === []);
putenv('TALIXIO_MISTRAL_BATCH');
putenv('TALIXIO_SOVEREIGN=scaleway');
$sc = App::fromEnv();
check('batch: only for Mistral', !(new Batch($sc))->eligible());
putenv('TALIXIO_SOVEREIGN=mistral');
putenv('TALIXIO_BUDGET_DAILY_USD=0.000001');
$low = App::fromEnv();
check('batch: not when the budget runs low', $low->budget()->status()['state'] !== 'ok' && !(new Batch($low))->eligible());
putenv('TALIXIO_BUDGET_DAILY_USD');

// ---- the Mind: consolidation and portraits by batch, "think it over now" direct
putenv('TALIXIO_MIND=1');
$m = App::fromEnv();
$ms = $m->store;
$ms->q("INSERT INTO mind_obs(ts, kind, text, strength, space, source, messages) VALUES(?, 'value', 'Values short meetings', 2, 0, 'test', '')", [time()]);
$ms->set('mind_bootstrap', 'done');
$ms->set('mind_consolidated', '0');
$routes([]);
$clear();
check('batch: the Mind sends its consolidation as a batch', $m->mind()->tick(time()) === false && count($calls('POST /v1/batch/jobs')) === 1
    && (int)$ms->q('SELECT COUNT(*) FROM mind_obs WHERE processed = 0')->fetchColumn() === 1);
$ms->q("INSERT INTO mind_obs(ts, kind, text, strength, space, source, messages) VALUES(?, 'value', 'Prefers email', 1, 0, 'test', '')", [time()]);
$routes(['GET /v1/batch/jobs/job-1' => [200, ['status' => 'SUCCESS', 'output_file' => 'file-out']],
    'GET /v1/files/file-out/content' => [200, $output('{"ops":[{"op":"new","kind":"value","scope":"global","text":"Likes short meetings.","obs":[1,2]}]}')]]);
$ms->q('UPDATE batches SET checked = 0');
(new Batch($m))->poll(time());
check('batch: the Mind uses the answer for the observations it sent', $m->mind()->tick(time()) === true
    && $ms->q('SELECT text FROM mind_beliefs')->fetchColumn() === 'Likes short meetings.'
    && $ms->q('SELECT processed FROM mind_obs ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) === [1, 0]
    && (int)$ms->q('SELECT COUNT(*) FROM mind_links WHERE obs = 2')->fetchColumn() === 0);
$routes(['POST /v1/chat/completions' => [200, ['choices' => [['message' => ['content' => '{"ops":[]}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]]]);
$clear();
$m->mind()->thinkNow(time());
check('batch: "think it over now" never waits', $calls('POST /v1/files') === [] && count($calls('POST /v1/chat/completions')) >= 1
    && (int)$ms->q('SELECT COUNT(*) FROM mind_obs WHERE processed = 0')->fetchColumn() === 0);

// ---- the setting is on the Settings page
check('batch: the setting is listed', str_contains((string)file_get_contents(dirname(__DIR__, 2) . '/src/Settings.php'), "'TALIXIO_MISTRAL_BATCH', 'Mistral: background work at half price'"));
foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
putenv('TALIXIO_MISTRAL_BATCH');
