<?php
declare(strict_types=1);
// Audit S22 (7.3): the saved secrets are encrypted at rest with a key kept out of the data folder.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, JsonFile, Secrets, Settings};

$d = test_dir('secrets');
$keep = [];
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_SECRET_KEY' => '', 'TALIXIO_SECRET_KEY_FILE' => "$d/none.key"] as $k => $v) {
    $keep[$k] = getenv($k); putenv($v === '' ? $k : "$k=$v");
}
Secrets::reset();

// ---- without a key: as before (plain), and the doctor says so
check('secrets: without a key, values are kept as they are', !Secrets::enabled() && Secrets::seal('sk-1') === 'sk-1' && Secrets::open('sk-1') === 'sk-1');
$app = App::fromEnv();
Settings::save($d, ['ANTHROPIC_API_KEY' => 'sk-ant-plain-before', 'TALIXIO_NAME' => 'Robin']);
check('secrets: (before a key) settings.json holds the key in clear', str_contains((string)file_get_contents(Settings::file($d)), 'sk-ant-plain-before'));
$doc = array_values(array_filter((new \Talixio\Doctor($app))->run(false), fn($r) => str_contains($r['text'], 'saved secrets')))[0] ?? [];
check('secrets: the doctor says when secrets are not encrypted', ($doc['status'] ?? '') === 'fail' && str_contains($doc['text'], 'not encrypted'), json_encode($doc));

// ---- a key file (install.sh makes one)
$k1 = Secrets::newKey();
file_put_contents("$d/secret.key", $k1 . "\n"); putenv("TALIXIO_SECRET_KEY_FILE=$d/secret.key"); Secrets::reset();
$s = Secrets::seal('sk-ant-123');
check('secrets: a sealed value is not the plain one and opens back', Secrets::enabled() && Secrets::sealed($s) && !str_contains($s, 'sk-ant-123') && Secrets::open($s) === 'sk-ant-123'
    && Secrets::seal($s) === $s && Secrets::seal('') === '' && Secrets::seal('sk-ant-123') !== $s);
$doc = array_values(array_filter((new \Talixio\Doctor($app))->run(false), fn($r) => str_contains($r['text'], 'saved secrets')))[0] ?? [];
check('secrets: the doctor says they are encrypted, and with which key', ($doc['status'] ?? '') === 'ok' && str_contains($doc['text'], "$d/secret.key"), json_encode($doc));

// ---- migrate: what was saved in clear before is sealed at the worker's start
$app = App::fromEnv();
JsonFile::write("$d/google.json", ['refresh_token' => 'g-refresh-plain', 'access_token' => 'g-acc']); // tokens from before
$app->store->set('model_accounts', json_encode(['old' => ['id' => 'old', 'kind' => 'anthropic', 'name' => 'Old', 'key' => 'sk-acc-plain']]));
file_put_contents("$d/mcp.json", json_encode(['mcpServers' => ['notes' => ['url' => 'https://notes.example/mcp', 'headers' => ['Authorization' => 'Bearer mcp-plain-token']]]]));
check('secrets: (before migrating) everything is readable as it was', Settings::load($d)['ANTHROPIC_API_KEY'] === 'sk-ant-plain-before' && $app->google()->tokens()['refresh_token'] === 'g-refresh-plain'
    && ($app->accounts()->get('old', true)['key'] ?? '') === 'sk-acc-plain' && $app->mcp()->servers()['notes']['headers']['Authorization'] === 'Bearer mcp-plain-token');
$n = Secrets::migrate($app);
$raw = (string)file_get_contents(Settings::file($d)) . file_get_contents("$d/google.json") . file_get_contents("$d/mcp.json") . $app->store->get('model_accounts');
check('secrets: migrating seals what was in clear (settings, tokens, connectors, AI accounts)', $n === 4
    && !str_contains($raw, 'sk-ant-plain-before') && !str_contains($raw, 'g-refresh-plain') && !str_contains($raw, 'mcp-plain-token') && !str_contains($raw, 'sk-acc-plain')
    && str_contains($raw, 'Robin') && str_contains($raw, 'notes.example'), "$n " . substr($raw, 0, 400));
check('secrets: once sealed, everything reads the same, and migrating again writes nothing', Settings::load($d)['ANTHROPIC_API_KEY'] === 'sk-ant-plain-before'
    && $app->google()->tokens()['refresh_token'] === 'g-refresh-plain' && ($app->accounts()->get('old', true)['key'] ?? '') === 'sk-acc-plain'
    && $app->mcp()->servers()['notes']['headers']['Authorization'] === 'Bearer mcp-plain-token' && str_contains($app->mcp()->raw(), 'Bearer mcp-plain-token')
    && Secrets::migrate($app) === 0);
check('secrets: the files stay private', (fileperms(Settings::file($d)) & 0777) === 0600 && (fileperms("$d/google.json") & 0777) === 0600 && (fileperms("$d/mcp.json") & 0777) === 0600);

// ---- new values are sealed as they are written
Settings::save($d, ['OPENAI_API_KEY' => 'sk-openai-new']);
Settings::setTotp($d, 'JBSWY3DPEHPK3PXP');
$acc = $app->accounts()->save(['name' => 'Second', 'kind' => 'anthropic', 'key' => 'sk-acc-new']);
$app->mcp()->saveRaw(json_encode(['mcpServers' => ['x' => ['command' => 'x', 'env' => ['X_TOKEN' => 'env-secret-value']]]]));
$raw = (string)file_get_contents(Settings::file($d)) . file_get_contents("$d/mcp.json") . $app->store->get('model_accounts');
check('secrets: a new API key, the two-step secret, an account key, a connector token are sealed when saved', !str_contains($raw, 'sk-openai-new') && !str_contains($raw, 'JBSWY3DPEHPK3PXP')
    && !str_contains($raw, 'sk-acc-new') && !str_contains($raw, 'env-secret-value'));
Settings::apply($d);
check('secrets: the app reads them in clear', getenv('OPENAI_API_KEY') === 'sk-openai-new' && (new \Talixio\Auth($app))->totpSecret() === 'JBSWY3DPEHPK3PXP'
    && ($app->accounts()->get($acc['id'], true)['key'] ?? '') === 'sk-acc-new' && $app->mcp()->servers()['x']['env']['X_TOKEN'] === 'env-secret-value');
putenv('OPENAI_API_KEY'); putenv('ANTHROPIC_API_KEY');

// ---- the federation's secret, sealed in the database
$app->store->q("INSERT INTO fed_peers(ts, name, url, out_secret, in_hash, give, they_give, accept, status) VALUES (?,?,?,?,?,?,?,?,?)", [time(), 'P', 'https://p.test', 'fed-plain-secret', 'h', '[]', '[]', '[]', 'active']);
check('secrets: the federation secret saved in clear before is sealed by migrating, and still opens', $app->federation()->reseal() === 1
    && Secrets::open((string)$app->store->q("SELECT out_secret FROM fed_peers WHERE name = 'P'")->fetchColumn()) === 'fed-plain-secret'
    && !str_contains((string)$app->store->q("SELECT out_secret FROM fed_peers WHERE name = 'P'")->fetchColumn(), 'fed-plain') && $app->federation()->reseal() === 0);

// ---- the wrong key (the key file lost, or replaced): nothing is lost
file_put_contents("$d/secret.key", Secrets::newKey()); Secrets::reset(); Secrets::$unreadable = 0;
$open = Settings::load($d)['OPENAI_API_KEY'] ?? '';
check('secrets: with another key, a secret does not open (and is not given in clear)', Secrets::sealed($open) && Secrets::$unreadable > 0);
Settings::save($d, ['TALIXIO_NAME' => 'Robin B']);
file_put_contents("$d/secret.key", $k1); Secrets::reset();
check('secrets: saving other settings meanwhile loses no secret: they open again with the right key', (Settings::load($d)['OPENAI_API_KEY'] ?? '') === 'sk-openai-new'
    && Settings::load($d)['TALIXIO_NAME'] === 'Robin B');
file_put_contents("$d/secret.key", Secrets::newKey()); Secrets::reset(); $before = file_get_contents(Settings::file($d));
$n = Secrets::migrate($app);
check('secrets: values no key opens are left alone by migrating', $n === 0 && file_get_contents(Settings::file($d)) === $before, (string)$n);
file_put_contents("$d/secret.key", $k1); Secrets::reset();

// ---- a key given by the multi-user server (TALIXIO_SECRET_KEY) wins over the file
$k2 = Secrets::newKey(); putenv("TALIXIO_SECRET_KEY=$k2"); Secrets::reset();
check('secrets: the server\'s key wins over the key file', Secrets::exportKey() === $k2 && !Secrets::stale(Secrets::seal('x')) && Secrets::open(Secrets::seal('x')) === 'x');
check('secrets: a bad key is no key', (function () { putenv('TALIXIO_SECRET_KEY=not-a-key'); putenv('TALIXIO_SECRET_KEY_FILE=/nonexistent'); Secrets::reset(); return !Secrets::enabled(); })());
$fs = ['instances' => ['alice' => ['memory_mb' => 512, 'cpus' => 1, 'name' => 'A']], 'settings' => [], 'secret_root' => str_repeat('ab', 32)];
check('secrets: each assistant of a multi-user server gets its own key, the same at every start', preg_match('/^[0-9a-f]{64}$/', \Talixio\Fleet::secretKey($fs, 'alice')) === 1
    && \Talixio\Fleet::secretKey($fs, 'alice') !== \Talixio\Fleet::secretKey($fs, 'bob') && \Talixio\Fleet::secretKey($fs, 'alice') === \Talixio\Fleet::secretKey($fs, 'alice'));

foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
Secrets::reset(); Secrets::$unreadable = 0;
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
