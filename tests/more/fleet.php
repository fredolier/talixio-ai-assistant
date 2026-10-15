<?php
declare(strict_types=1);
// The multi-user server: the agent (Fleet) against a fake Docker Engine on a unix socket, the admin page
// (AdminWeb) in front of the real agent, and "Sign in with Claude" (ClaudeLogin) with a fake Claude Code.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, AdminWeb, Auth, ClaudeLogin, Config, Fleet, Settings};

$d = test_dir('fleet');
$root = dirname(__DIR__, 2);
$envKeep = [];
foreach (['TALIXIO_GIT_URL', 'TALIXIO_GIT_TOKEN', 'CADDY_CONFIG_DIR', 'PATH', 'TALIXIO_DATA_DIR', 'TALIXIO_CLAUDE_BIN', 'TALIXIO_PROVIDER', 'TALIXIO_GIT_BRANCH', 'TALIXIO_BEHIND_PROXY'] as $k) $envKeep[$k] = getenv($k);
$fleetRestoreEnv = function () use ($envKeep) { foreach ($envKeep as $k => $v) putenv($v === false ? $k : "$k=$v"); };

// ---------- a fake Docker Engine: rules (first match wins) in rules.json, every request logged ----------
file_put_contents("$d/fake-docker.php", <<<'PHP'
<?php
[, $sock, $rulesF, $logF] = $argv;
@unlink($sock);
$srv = stream_socket_server("unix://$sock", $en, $es);
$used = [];
while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if (!$c) continue;
    $line = fgets($c);
    if ($line === false) { fclose($c); continue; }
    [$m, $uri] = explode(' ', trim($line)) + [1 => '/'];
    $len = 0; $chunked = false; $expect = false;
    while (($h = fgets($c)) !== false && trim($h) !== '') {
        if (stripos($h, 'content-length:') === 0) $len = (int)trim(substr($h, 15));
        if (stripos($h, 'transfer-encoding:') === 0 && stripos($h, 'chunked') !== false) $chunked = true;
        if (stripos($h, 'expect:') === 0 && stripos($h, '100-continue') !== false) $expect = true;
    }
    if ($expect) fwrite($c, "HTTP/1.1 100 Continue\r\n\r\n");
    $body = '';
    if ($chunked) { while (($sz = hexdec(trim((string)fgets($c)))) > 0) { $body .= stream_get_contents($c, $sz); fgets($c); } fgets($c); }
    elseif ($len) { while (strlen($body) < $len && !feof($c)) $body .= fread($c, $len - strlen($body)); }
    $path = preg_replace('#^/v1\.44#', '', $uri);
    file_put_contents($logF, json_encode(['m' => $m, 'p' => $path, 'b' => strlen($body) > 2000000 ? '(big)' : $body], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    $rules = json_decode((string)@file_get_contents($rulesF), true) ?: [];
    $resp = [200, '{}'];
    foreach ($rules as $r) {
        if (($r[0] !== '*' && $r[0] !== $m) || !preg_match($r[1], $path)) continue;
        if (isset($r['body']) && !preg_match($r['body'], $body)) continue;
        if (isset($r['once'])) { if (isset($used[$r['once']])) continue; $used[$r['once']] = 1; }
        $out = $r['raw'] ?? (array_key_exists(3, $r) ? json_encode($r[3], JSON_UNESCAPED_SLASHES) : '');
        if (isset($r['frames'])) { $out = ''; foreach ((array)$r['frames'] as $f) $out .= pack('CxxxN', 1, strlen($f)) . $f; }
        $resp = [$r[2], $out];
        break;
    }
    @fwrite($c, "HTTP/1.1 {$resp[0]} X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($resp[1]) . "\r\nConnection: close\r\n\r\n" . $resp[1]);
    fclose($c);
}
PHP);
$sock = "$d/docker.sock"; $rulesF = "$d/rules.json"; $logF = "$d/docker.log";
$plainEnv = getenv(); unset($plainEnv['PHP_INI_SCAN_DIR']); // the fake itself needs no coverage
$dk = proc_open([PHP_BINARY, "$d/fake-docker.php", $sock, $rulesF, $logF], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$d/fake-docker.out", 'w'], 2 => ['file', "$d/fake-docker.out", 'a']], $pp, null, $plainEnv);
register_shutdown_function(function () use ($dk) { proc_terminate($dk, 9); proc_close($dk); });
for ($i = 0; $i < 100 && !file_exists($sock); $i++) usleep(20000);

$running = ['State' => ['Status' => 'running', 'StartedAt' => '2026-10-14T08:00:00.123Z', 'Health' => ['Status' => 'healthy', 'FailingStreak' => 0, 'Log' => [
    ['Start' => '2026-10-14T08:00:00.100000000Z', 'End' => '2026-10-14T08:00:00.150000000Z', 'ExitCode' => 0, 'Output' => '']]]],
    'Config' => ['Image' => 'talixio:current'], 'HostConfig' => ['Memory' => 1073741824], 'RestartCount' => 1];
$defaults = [
    ['GET', '#^/networks/#', 200, ['Name' => 'n']],
    ['GET', '#^/volumes/#', 200, ['Name' => 'v']],
    ['POST', '#^/containers/create#', 201, ['Id' => 'c1']],
    ['POST', '#^/containers/[^/]+/(start|stop|restart)#', 204],
    ['POST', '#^/containers/[^/]+/exec$#', 201, ['Id' => 'e0']],
    ['POST', '#^/exec/[^/]+/start#', 200, 'frames' => ['ok']],
    ['GET', '#^/exec/[^/]+/json#', 200, ['ExitCode' => 0]],
    ['GET', '#^/containers/talixio-ollama/json#', 200, ['State' => ['Status' => 'running']]],
    ['GET', '#^/containers/[^/]+/json#', 200, $running],
    ['GET', '#^/containers/[^/]+/stats#', 200, ['memory_stats' => ['usage' => 300 * 1048576, 'stats' => ['inactive_file' => 100 * 1048576]]]],
    ['GET', '#^/containers/[^/]+/logs#', 200, 'frames' => ['']],
    ['POST', '#^/images/.*/tag#', 201],
    ['GET', '#^/images/#', 200, ['Id' => 'sha256:cur']],
    ['DELETE', '#.#', 204],
    ['*', '#.#', 200, []],
];
$rules = function (array $specific = []) use ($rulesF, $defaults) { file_put_contents($rulesF, json_encode(array_merge($specific, $defaults), JSON_UNESCAPED_SLASHES)); };
$dlog = function () use ($logF): array { // the requests since the last call, as "METHOD path"
    $l = array_values(array_filter(explode("\n", (string)@file_get_contents($logF))));
    @file_put_contents($logF, '');
    return array_map(fn($x) => json_decode($x, true), $l);
};
$has = fn(array $log, string $m, string $re) => (bool)array_filter($log, fn($r) => $r['m'] === $m && preg_match($re, $r['p']));
$throws = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } };
$rules();

// ---------- Fleet, in process ----------
check('fleet: Docker not reachable is a clear error', str_contains($throws(fn() => (new Fleet("$d/st0", "$d/none.sock"))->ollamaState()), 'Docker is not reachable'));
$f = new Fleet("$d/st", $sock);

// settings
$set = $f->setSettings(['domain' => ' Assistant.ACME.example ', 'mode' => 'subdomain', 'memory_mb' => 99999, 'cpus' => 0.01, 'ollama' => 0, 'ollama_model' => 'nomic-embed-text:v1.5',
    'google_client_id' => ' gid ', 'google_client_secret' => 'gsecret', 'apparmor_unconfined' => false, 'eu_provider' => 'mistral', 'eu_key' => 'sk-eu', 'eu_url' => 'https://eu.acme.example/v1/', 'eu_only' => 1]);
check('fleet: settings are normalised and clamped', $set['domain'] === 'assistant.acme.example' && $set['mode'] === 'subdomain' && $set['memory_mb'] === 16384 && $set['cpus'] === 0.25
    && $set['ollama'] === false && $set['ollama_model'] === 'nomic-embed-text:v1.5' && $set['google_client_id'] === 'gid' && $set['eu_url'] === 'https://eu.acme.example/v1' && $set['eu_only'] === true, json_encode($set));
$errs = [$throws(fn() => $f->setSettings(['mode' => 'cloud'])), $throws(fn() => $f->setSettings(['ollama_model' => 'bad model!'])),
    $throws(fn() => $f->setSettings(['eu_provider' => 'nowhere'])), $throws(fn() => $f->setSettings(['eu_url' => 'http://eu.acme.example']))];
check('fleet: bad settings are refused with a reason', str_contains($errs[0], 'path or subdomain') && str_contains($errs[1], 'Unexpected model') && str_contains($errs[2], 'Unknown European') && str_contains($errs[3], 'https://'), json_encode($errs));
check('fleet: subdomain addresses', $f->url('alice') === 'https://alice.assistant.acme.example/');
$st = $f->state(); $st['instances']['alice'] = ['name' => 'Alice', 'email' => '', 'memory_mb' => 1024, 'cpus' => 1.5, 'created' => 1, 'password_hash' => '', 'reset' => 'abc:totp'];
$env = $f->spec('alice', $st)['Env']; $sec = $f->spec('alice', $st)['HostConfig']['SecurityOpt'];
check('fleet: the template carries Google, the European provider and a pending reset, without Ollama or AppArmor', in_array('TALIXIO_GOOGLE_CLIENT_SECRET=gsecret', $env, true) && in_array('TALIXIO_SOVEREIGN_URL=https://eu.acme.example/v1', $env, true)
    && in_array('TALIXIO_SOVEREIGN_ONLY=1', $env, true) && in_array('TALIXIO_RESET=abc:totp', $env, true) && !preg_grep('/^TALIXIO_EMBEDDINGS=/', $env) && !in_array('apparmor=unconfined', $sec, true)
    && in_array('TALIXIO_BASE_PATH=', $env, true) && in_array('TZ=Europe/Paris', $env, true), json_encode($env));
$f->setSettings(['domain' => 'localhost', 'mode' => 'path', 'memory_mb' => 512, 'cpus' => 1, 'ollama' => true, 'eu_provider' => '', 'google_client_id' => '', 'apparmor_unconfined' => true]);
check('fleet: path addresses', $f->url('alice') === 'https://localhost/alice/');

// create
$rules([['GET', '#^/networks/talixio-i-alice#', 404, ['message' => 'no such network'], 'once' => 'n1'], ['GET', '#^/volumes/talixio-alice#', 404, [], 'once' => 'v1'],
    ['POST', '#^/networks/talixio-i-alice/connect#', 409, ['message' => 'endpoint with name talixio-caddy already exists in network']]]);
$dlog();
$r = $f->create('alice', ' Alice Martin ', 'alice@acme.example', null, 2.0);
$lg = $dlog();
$si = $f->state()['instances']['alice'] ?? [];
check('fleet: create makes the network, the volume and the container, then joins Caddy', $has($lg, 'POST', '#^/networks/create#') && $has($lg, 'POST', '#^/volumes/create#')
    && $has($lg, 'POST', '#^/containers/create\?name=talixio-alice#') && $has($lg, 'POST', '#^/containers/talixio-alice/start#') && $has($lg, 'POST', '#^/networks/talixio-i-alice/connect#'), json_encode($lg));
check('fleet: create returns a first password that the template carries hashed', strlen($r['password']) === 20 && $r['url'] === 'https://localhost/alice/' && $si['name'] === 'Alice Martin'
    && $si['memory_mb'] === 512 && (float)$si['cpus'] === 2.0 && password_verify($r['password'], $si['password_hash']), json_encode($si));
check('fleet: an identifier is used once', str_contains($throws(fn() => $f->create('alice')), 'already exists') && str_contains($throws(fn() => $f->create('Bad Id')), 'lower-case'));
$rules([['POST', '#^/containers/create#', 500, ['message' => 'no space left on device']], ['DELETE', '#^/containers/talixio-bob#', 500, 'raw' => 'daemon busy']]);
$e = $throws(fn() => $f->create('bob'));
check('fleet: a failed create says why and leaves no instance behind', str_contains($e, 'create talixio-bob: no space left on device (HTTP 500)') && !isset($f->state()['instances']['bob']), $e);
$rules([['POST', '#^/networks/talixio-i-carol/connect#', 500, ['message' => 'network gone']]]);
$e = $throws(fn() => $f->create('carol'));
check('fleet: a container Caddy cannot reach is not kept', str_contains($e, 'connect talixio-caddy to talixio-i-carol: network gone') && !isset($f->state()['instances']['carol']), $e);

// Caddy reloads (a config dir means Caddy is managed here)
@mkdir("$d/caddy");
putenv("CADDY_CONFIG_DIR=$d/caddy");
$rules([['POST', '#^/containers/talixio-caddy/exec#', 201, ['Id' => 'ecaddy']], ['POST', '#^/exec/ecaddy/start#', 200, 'frames' => ['Error: adapting config: bad directive']], ['GET', '#^/exec/ecaddy/json#', 200, ['ExitCode' => 1]]]);
$e = $throws(fn() => $f->reloadCaddy());
check('fleet: Caddy refusing the configuration is reported, the file is written', str_contains($e, 'Caddy refused the configuration: Error: adapting config: bad directive') && str_contains((string)@file_get_contents("$d/caddy/Caddyfile"), 'reverse_proxy talixio-alice:8080'), $e);
$rules(); $dlog();
$f->adopt('dave', 'Dave');
$lg = $dlog();
check('fleet: adopt keeps the volume, makes the container and reloads Caddy', $has($lg, 'DELETE', '#^/containers/talixio-dave\?force=true#') && $has($lg, 'POST', '#^/containers/create\?name=talixio-dave#')
    && $has($lg, 'POST', '#^/containers/talixio-caddy/exec#') && $f->state()['instances']['dave']['password_hash'] === '' && str_contains($throws(fn() => $f->adopt('x')), 'invalid identifier'));
putenv('CADDY_CONFIG_DIR'); if ($envKeep['CADDY_CONFIG_DIR'] !== false) putenv('CADDY_CONFIG_DIR=' . $envKeep['CADDY_CONFIG_DIR']);
putenv("CADDY_CONFIG_DIR=$d/no-caddy");

// simple actions
$dlog();
$f->start('alice'); $f->stop('alice'); $f->restart('alice');
$lg = $dlog();
check('fleet: start, stop, restart reach the container', $has($lg, 'POST', '#^/containers/talixio-alice/start$#') && $has($lg, 'POST', '#^/containers/talixio-alice/stop\?t=15#') && $has($lg, 'POST', '#^/containers/talixio-alice/restart\?t=15#'));
check('fleet: actions on an unknown instance are refused', str_contains($throws(fn() => $f->start('zed')), 'no instance zed'));
$rules([['POST', '#^/containers/talixio-alice/stop#', 500, 'raw' => 'container is restarting']]);
check('fleet: a Docker error without JSON is shown as text', str_contains($throws(fn() => $f->stop('alice')), 'stop: container is restarting (HTTP 500)'));
$rules(); $dlog();
$f->setLimits('alice', 99999, 0.1);
$si = $f->state()['instances']['alice'];
$lg = $dlog();
$created = array_values(array_filter($lg, fn($x) => str_starts_with($x['p'], '/containers/create')));
$spec = json_decode($created[0]['b'] ?? '{}', true);
check('fleet: limits are clamped and the container made again with them', $si['memory_mb'] === 16384 && $si['cpus'] === 0.25 && ($spec['HostConfig']['Memory'] ?? 0) === 16384 * 1048576 && ($spec['HostConfig']['NanoCpus'] ?? 0) === 250000000, json_encode([$si, $spec['HostConfig'] ?? null]));
// one sign-in across a person's assistants
check('fleet: a link to itself or to no one is refused', str_contains($throws(fn() => $f->setLinks('alice', ['alice'])), 'itself') && str_contains($throws(fn() => $f->setLinks('alice', ['zed'])), 'no instance zed'));
$dlog();
$f->setLinks('alice', ['dave']);
$st = $f->state(); $lg = $dlog();
$envOf = fn(string $id) => $f->spec($id, $f->state())['Env'];
$linksOf = fn(string $id) => json_decode(substr((string)current(preg_grep('/^TALIXIO_LINKS=/', $envOf($id)) ?: ['TALIXIO_LINKS=[]']), 14), true);
check('fleet: links go both ways and both containers are made again', $st['instances']['alice']['links'] === ['dave'] && $st['instances']['dave']['links'] === ['alice'] && strlen($st['sso_key']) === 64
    && $has($lg, 'POST', '#^/containers/create\?name=talixio-alice#') && $has($lg, 'POST', '#^/containers/create\?name=talixio-dave#'), json_encode($lg));
$la = $linksOf('alice');
check('fleet: each one carries its own key and the key of the one it opens', ($la[0]['id'] ?? '') === 'dave' && $la[0]['url'] === 'https://localhost/dave/' && $la[0]['key'] === Fleet::ssoKey($st, 'dave')
    && in_array('TALIXIO_SSO_KEY=' . Fleet::ssoKey($st, 'alice'), $envOf('alice'), true) && $linksOf('dave')[0]['key'] === Fleet::ssoKey($st, 'alice') && in_array('TALIXIO_INSTANCE_NAME=Dave', $envOf('dave'), true));
check('fleet: the list shows the links', array_column($f->list(), 'links', 'id')['alice'] === ['dave']);
$dlog(); $f->setLinks('alice', ['dave']);
check('fleet: the same links change nothing', !$has($dlog(), 'POST', '#^/containers/create#'));
$oldDave = Fleet::ssoKey($st, 'dave');
$f->setLinks('dave', []);
$st = $f->state(); $lg = $dlog();
check('fleet: removing a link removes both sides and changes both keys', ($st['instances']['alice']['links'] ?? null) === [] && $st['instances']['dave']['links'] === [] && Fleet::ssoKey($st, 'dave') !== $oldDave
    && !preg_grep('/^TALIXIO_(LINKS|SSO_KEY)=/', $envOf('alice')) && $has($lg, 'POST', '#^/containers/create\?name=talixio-alice#'), json_encode($st['instances']['alice']));
$f->setLinks('alice', ['dave']);
$pw = $f->resetPassword('alice');
$reset = $f->state()['instances']['alice']['reset'];
check('fleet: a password reset is applied once through the template', strlen($pw) === 20 && str_contains($reset, ':password:') && password_verify($pw, explode(':password:', $reset)[1]));
$f->resetTotp('alice');
check('fleet: a two-step reset is recorded', str_ends_with($f->state()['instances']['alice']['reset'], ':totp'));
$rules([['DELETE', '#^/containers/talixio-alice#', 500, ['message' => 'removal in progress']]]);
check('fleet: a container that cannot be removed is an error', str_contains($throws(fn() => $f->recreate('alice')), 'remove talixio-alice: removal in progress'));

// logs, doctor, backup
$rules([['GET', '#^/containers/talixio-alice/logs#', 200, 'frames' => [
    "2026-10-14T08:00:01.123456789Z [Tue Oct 14 08:00:01 2026] 172.18.0.3:50000 Accepted\n",
    "2026-10-14T08:00:01.223456789Z [Tue Oct 14 08:00:01 2026] 172.18.0.3:50000 [200]: GET /api/me\n",
    "2026-10-14T08:00:01.323456789Z [Tue Oct 14 08:00:01 2026] 172.18.0.3:50000 [500]: POST /api/chat\n",
    "2026-10-14T08:00:01.423456789Z [Tue Oct 14 08:00:01 2026] 172.18.0.3:50000 Closing\n",
    "2026-10-14T08:00:02.000000000Z PHP Warning: something odd\n"]], ['GET', '#^/containers/talixio-dave/logs#', 404, ['message' => 'no such container']]]);
$short = $f->logs('alice'); $all = $f->logs('alice', true);
check('fleet: logs keep failed requests and warnings, without the noise, timestamps to the second', $short === "2026-10-14T08:00:01Z [Tue Oct 14 08:00:01 2026] 172.18.0.3:50000 [500]: POST /api/chat\n2026-10-14T08:00:02Z PHP Warning: something odd"
    && substr_count($all, "\n") === 4 && str_contains($throws(fn() => $f->logs('dave')), 'logs: HTTP 404'), $short);
$rules([['POST', '#^/containers/talixio-alice/exec#', 201, ['Id' => 'edoc'], 'body' => '#doctor#'], ['POST', '#^/exec/edoc/start#', 200, 'frames' => ["Database: ok\n", "Disk: ok\n"]],
    ['POST', '#^/containers/talixio-alice/exec#', 201, ['Id' => 'ebak'], 'body' => '#backup#'], ['POST', '#^/exec/ebak/start#', 200, 'frames' => ["/data/backups/b1.tar.gz\n"]]]);
check('fleet: doctor and backup run inside the container', $f->doctor('alice') === "Database: ok\nDisk: ok\n" && $f->backup('alice') === '/data/backups/b1.tar.gz');
$rules([['POST', '#^/containers/talixio-alice/exec#', 201, ['Id' => 'ebak2']], ['POST', '#^/exec/ebak2/start#', 200, ['not' => 'a stream']], ['GET', '#^/exec/ebak2/json#', 200, ['ExitCode' => 2]],
    ['POST', '#^/containers/talixio-dave/exec#', 404, ['message' => 'No such container: talixio-dave']]]);
check('fleet: a failed backup or exec is an error', str_contains($throws(fn() => $f->backup('alice')), 'RuntimeException') && str_contains($throws(fn() => $f->doctor('dave')), 'exec: No such container'));

// list and server
$rules([['GET', '#^/containers/talixio-alice/logs#', 200, 'raw' => "2026-10-14T09:00:00.1Z [x] 172.18.0.3:5 Accepted\n2026-10-14T09:30:00.1Z [x] 127.0.0.1:5 Accepted\n2026-10-14T07:00:00.1Z [x] 10.0.0.9:5 Accepted\n"],
    ['GET', '#^/containers/talixio-dave/json#', 404, ['message' => 'no such container']]]);
$ls = array_column($f->list(), null, 'id');
check('fleet: list shows state, memory in use and the last visit (not health checks)', $ls['alice']['state'] === 'running' && $ls['alice']['mem_used_mb'] === 200 && $ls['alice']['health'] === 'healthy'
    && $ls['alice']['restarts'] === 1 && $ls['alice']['check_ms'] === 50 && $ls['alice']['last_activity'] === strtotime('2026-10-14T07:00:00Z') && $ls['dave']['state'] === 'missing' && $ls['dave']['mem_used_mb'] === null, json_encode($ls));
$rules([['GET', '#^/system/df#', 200, ['LayersSize' => 3e9, 'Volumes' => [['Name' => 'talixio-alice', 'UsageData' => ['Size' => 50 * 1048576]], ['Name' => 'other', 'UsageData' => ['Size' => -1]]],
    'BuildCache' => [['Size' => 2.5e9]]]], ['GET', '#^/info#', 200, ['NCPU' => 4, 'OperatingSystem' => 'Debian GNU/Linux 12', 'KernelVersion' => '6.1', 'ServerVersion' => '27.0']],
    ['POST', '#^/containers/talixio-ollama/exec#', 201, ['Id' => 'eoll']], ['POST', '#^/exec/eoll/start#', 200, 'frames' => ["NAME                    ID           SIZE      MODIFIED\nqwen3-embedding:0.6b    abc123    639 MB    2 days ago\n"]],
    ['GET', '#^/containers/talixio-caddy/json#', 200, $running], ['GET', '#^/containers/talixio-admin/json#', 404, []]]);
$srv = $f->server();
$svc = array_column($srv['services'], null, 'name');
check('fleet: server shows volumes, processors, the host and the models', $srv['volumes_mb'] === ['alice' => 50] && $srv['cpu']['cores'] === 4 && $srv['host']['docker'] === '27.0'
    && $srv['ollama_models'] === [['name' => 'qwen3-embedding:0.6b', 'size' => '639 MB']] && ($srv['disk'] === null || $srv['disk']['cache_gb'] === 2.5) && isset($srv['sizing']['level']), json_encode($srv['ollama_models']));
check('fleet: the shared services: Caddy probed, the agent answers, the admin page missing', $svc['caddy']['state'] === 'running' && $svc['caddy']['answers'] === false && $svc['caddy']['mem_used_mb'] === 200
    && $svc['agent']['answers'] === true && $svc['admin']['state'] === 'missing' && !isset($svc['ollama']['disabled']), json_encode($svc['caddy']));
$f->setSettings(['ollama' => false]);
$rules([['GET', '#^/system/df#', 500, 'raw' => 'busy'], ['GET', '#^/info#', 500, 'raw' => 'busy'], ['GET', '#^/containers/talixio-ollama/json#', 200, ['State' => ['Status' => 'exited']]]]);
$srv = $f->server();
check('fleet: server copes with Docker errors; Ollama turned off is marked so', $srv['volumes_mb'] === [] && $srv['host']['os'] === '' && $srv['ollama_models'] === []
    && !empty(array_column($srv['services'], null, 'name')['ollama']['disabled']));
$f->setSettings(['ollama' => true]);

// Ollama
$rules([['POST', '#^/containers/talixio-ollama/exec#', 500, ['message' => 'gone']]]);
$m1 = $f->ollamaModels();
$rules([['POST', '#^/containers/talixio-ollama/exec#', 201, ['Id' => 'eol2']], ['GET', '#^/exec/eol2/json#', 200, ['ExitCode' => 1]]]);
$m2 = $f->ollamaModels();
check('fleet: models of an Ollama that fails are an empty list', $m1 === [] && $m2 === []);
check('fleet: a model download needs a valid name', str_contains($throws(fn() => $f->pullModel('bad name')), 'Unexpected model name'));
$e = $throws(fn() => $f->pullModel());
check('fleet: a failed model download names the model of the settings', str_contains($e, 'ollama pull nomic-embed-text:v1.5'), $e);
$rules([['GET', '#^/containers/talixio-ollama/json#', 404, []]]);
check('fleet: no Ollama container, no download', str_contains($throws(fn() => $f->pullModel('all-minilm')), 'is stopped') && $f->ollamaState() === 'missing');
$rules([['POST', '#^/containers/talixio-ollama/exec#', 201, ['Id' => 'epull']], ['POST', '#^/exec/epull/start#', 200, 'frames' => ["pulling manifest\nsuccess\n"]]]);
check('fleet: a model download', $f->pullModel('all-minilm:l6') === 'all-minilm:l6 ready');
$dlog(); $f->setOllama(true); $f->setOllama(false); $lg = $dlog();
check('fleet: Ollama turned on and off', $has($lg, 'POST', '#^/containers/talixio-ollama/start#') && $has($lg, 'POST', '#^/containers/talixio-ollama/stop#'));
$rules([['POST', '#^/containers/talixio-ollama/start#', 500, ['message' => 'no image']]]);
check('fleet: Ollama that cannot start is an error', str_contains($throws(fn() => $f->setOllama(true)), 'start Ollama: no image'));
$rules();

// installed by install-stack.sh
$dlog(); $f->installed('release/2026'); $i1 = $f->state()['image']; $lg = $dlog();
$f->installed('bad branch;'); $i2 = $f->state()['image'];
check('fleet: what install-stack.sh built is recorded (tag, branch)', $has($lg, 'POST', '#^/images/talixio%3Acurrent/tag\?repo=talixio&tag=\d{8}-\d{6}#') && $i1['branch'] === 'release/2026' && $i2['branch'] === 'release/2026' && str_starts_with($i1['built'], 'talixio:'));

// remove
$dlog(); $f->remove('dave', true); $lg = $dlog();
check('fleet: remove with its data deletes the container, network and volume', $has($lg, 'DELETE', '#^/containers/talixio-dave#') && $has($lg, 'POST', '#^/networks/talixio-i-dave/disconnect#')
    && $has($lg, 'DELETE', '#^/networks/talixio-i-dave$#') && $has($lg, 'DELETE', '#^/volumes/talixio-dave$#') && !isset($f->state()['instances']['dave']) && !in_array('dave', $f->state()['instances']['alice']['links'] ?? [], true));

// transfer in
$tp = []; $prog = function (string $phase, string $msg) use (&$tp) { $tp[] = "$phase|$msg"; };
check('fleet: a transfer needs a valid identifier', str_contains($throws(fn() => $f->transferIn('A', '', 'https://old.acme.example', 'tx-1', false, $prog)), 'identifier'));
$rules([['GET', '#^/volumes/talixio-erin#', 404, []], ['GET', '#^/containers/talixio-transfer-erin/json#', 200, ['State' => ['Status' => 'exited', 'ExitCode' => 0]]],
    ['GET', '#^/containers/talixio-transfer-erin/logs#', 200, 'frames' => ["Copying the database\n", "Copied 120 files.\n"]]]);
$dlog(); $tp = [];
$f->transferIn('erin', 'Erin', 'https://old.acme.example', 'tx-abc', true, $prog);
$lg = $dlog();
$job = array_values(array_filter($lg, fn($x) => str_starts_with($x['p'], '/containers/create?name=talixio-transfer-erin')));
$jb = json_decode($job[0]['b'] ?? '{}', true);
check('fleet: a transfer pulls the copy in a locked one-off container, then starts the assistant', ($jb['Cmd'] ?? []) === ['transfer-pull', 'https://old.acme.example', 'tx-abc', '--url', 'https://localhost/erin', '--final']
    && ($jb['HostConfig']['CapDrop'] ?? []) === ['ALL'] && $has($lg, 'POST', '#^/volumes/create#') && $has($lg, 'POST', '#^/containers/create\?name=talixio-erin#')
    && end($tp) === 'done|Copied 120 files. Address: https://localhost/erin/' && in_array('copying|Copying from https://old.acme.example…', $tp, true) && in_array('starting|Starting erin…', $tp, true) && in_array('copying|Copied 120 files.', $tp, true) && isset($f->state()['instances']['erin']), json_encode($tp));
$rules([['GET', '#^/containers/talixio-transfer-erin/json#', 200, ['State' => ['Status' => 'exited', 'ExitCode' => 3]]],
    ['GET', '#^/containers/talixio-transfer-erin/logs#', 200, 'frames' => ["Wrong transfer code.\n"]]]);
$e = $throws(fn() => $f->transferIn('erin', 'Erin', 'https://old.acme.example', 'tx-abc', false, $prog));
check('fleet: a failed copy restarts what was there and says why', str_contains($e, 'The copy failed: Wrong transfer code.'), $e);
$rules([['GET', '#^/containers/talixio-transfer-erin/json#', 200, ['State' => ['Status' => 'exited', 'ExitCode' => 0]]], ['GET', '#^/containers/talixio-erin/json#', 200, ['State' => ['Status' => 'exited']]]]);
$e = $throws(fn() => $f->transferIn('erin', 'Erin', 'https://old.acme.example', 'tx-abc', false, $prog));
check('fleet: an assistant that does not start after the copy is reported', str_contains($e, 'erin does not start after the copy'), $e);
$rules();

// ---------- updates: a local git repository ----------
$repo = "$d/repo";
@mkdir("$repo/deploy/docker", 0777, true);
file_put_contents("$repo/deploy/docker/base.Dockerfile", "FROM debian:12\n");
file_put_contents("$repo/VERSION", "1.2.0\n");
file_put_contents("$repo/CHANGELOG.md", "# Changelog\n\n## 1.2.0\n- Faster search.\n\n## 1.1.0\n- Shared folders.\n\n## 1.0.0\n- First release.\n");
$git = fn(string ...$a) => exec('git -C ' . escapeshellarg($repo) . ' -c user.name=Alex -c user.email=alex@acme.example ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1');
$git('init', '-q', '-b', 'main'); $git('add', '.'); $git('commit', '-q', '-m', 'Release 1.2.0');
$url = "file://$repo";

check('fleet: no repository, no update or check', str_contains($throws(fn() => $f->update('main', $prog)), 'TALIXIO_GIT_URL is not set') && str_contains($throws(fn() => $f->checkUpdate('main')), 'TALIXIO_GIT_URL is not set'));
putenv("TALIXIO_GIT_URL=$url");
check('fleet: bad branch names are refused', str_contains($throws(fn() => $f->update('main; rm', $prog)), 'bad branch') && str_contains($throws(fn() => $f->checkUpdate('a b')), 'bad branch'));
check('fleet: a missing branch cannot be fetched', str_contains($throws(fn() => $f->update('nope', $prog)), 'Could not fetch the code'));

// a private repository without credentials, and with a token (never shown)
@mkdir("$d/bin");
file_put_contents("$d/bin/git", "#!/bin/sh\necho \"fatal: could not read Username for '$url': terminal prompts disabled \$GIT_CONFIG_VALUE_0\" >&2\nexit 128\n");
chmod("$d/bin/git", 0755);
putenv("PATH=$d/bin:" . $envKeep['PATH']);
$e1 = $throws(fn() => $f->update('main', $prog));
putenv('TALIXIO_GIT_TOKEN=ghp_secret123');
$e2 = $throws(fn() => $f->checkUpdate('main'));
putenv('TALIXIO_GIT_TOKEN'); putenv('PATH=' . $envKeep['PATH']);
check('fleet: a private repository without a token gets an explanation, the address is hidden', str_contains($e1, 'GIT_TOKEN in deploy/docker/.env') && str_contains($e1, '<repository>') && !str_contains($e1, $repo), $e1);
check('fleet: with a token, the token never shows in the error', str_contains($e2, 'Could not fetch the code') && !str_contains($e2, 'GIT_TOKEN in deploy') && !str_contains($e2, base64_encode('x-access-token:ghp_secret123')) === false || !str_contains($e2, 'ghp_secret123'), $e2);

// a full update: dependencies built (the base image is new), the new image, each instance moved, the admin page respawned
$admin = ['Id' => 'abcdef1234567890ffff', 'Name' => '/talixio-admin', 'Image' => 'sha256:old', 'Config' => ['Hostname' => 'abcdef123456', 'Image' => 'talixio:current', 'Labels' => [], 'ExposedPorts' => ['8080/tcp' => []]],
    'HostConfig' => ['Memory' => 134217728, 'PortBindings' => []], 'NetworkSettings' => ['Networks' => ['talixio-control' => ['Aliases' => ['abcdef123456', 'talixio-admin', 'admin']]]]];
$buildOk = ['POST', '#^/build\?#', 200, 'raw' => "{\"stream\":\"Step 1/2 : FROM debian:12\\n\"}\n{\"stream\":\" ---> abc\\n\"}\nnot json\n{\"stream\":\"Step 2/2 : RUN true\\n\"}\n"];
$rules([['GET', '#^/images/talixio-base#', 404, ['message' => 'no such image']], $buildOk, ['GET', '#^/containers/talixio-admin/json#', 200, $admin],
    ['GET', '#^/containers/talixio-agent/json#', 200, ['Image' => 'sha256:cur']]]);
$dlog(); $tp = [];
$f->update('main', $prog);
$lg = $dlog();
$img = $f->state()['image'];
$builds = array_values(array_filter($lg, fn($x) => str_starts_with($x['p'], '/build?')));
$respawn = array_values(array_filter($lg, fn($x) => str_starts_with($x['p'], '/containers/create?name=talixio-admin')));
$rb = json_decode($respawn[0]['b'] ?? '{}', true);
check('fleet: update builds the dependencies, then the image with its version', count($builds) === 2 && str_contains(urldecode($builds[0]['p']), 'dockerfile=deploy/docker/base.Dockerfile')
    && str_contains(urldecode($builds[1]['p']), 'VERSION_SUBJECT":"Release 1.2.0') && in_array('building|Step 1/2 : FROM debian:12', $tp, true), json_encode(array_column($builds, 'p')));
check('fleet: update moves every instance and keeps the previous image', $img['previous'] === 'talixio:previous' && $img['branch'] === 'main' && $has($lg, 'POST', '#^/images/sha256%3Acur/tag\?repo=talixio&tag=previous#')
    && in_array('rolling|Moving alice to the new version…', $tp, true) && end($tp) === 'done|Every instance runs the new version.', json_encode($tp));
check('fleet: the admin page is made again from the new image, with its networks and aliases', ($rb['Image'] ?? '') === 'talixio:current' && !isset($rb['Hostname'])
    && ($rb['NetworkingConfig']['EndpointsConfig']['talixio-control']['Aliases'] ?? null) === ['admin'] && str_contains($respawn[0]['b'] ?? '', '"Labels":{}') && $has($lg, 'POST', '#^/containers/talixio-admin/start#'), $respawn[0]['b'] ?? '');
check('fleet: the sources are cleaned up after the build', !glob(sys_get_temp_dir() . '/talixio-src-*.tar'));

// the build fails
$rules([['POST', '#^/build\?#', 200, 'raw' => "{\"stream\":\"Step 1/2 : FROM x\\n\"}\n{\"error\":\"no space left on device\"}\n"]]);
check('fleet: a failed build is reported', str_contains($throws(fn() => $f->update('main', $prog)), 'The build failed: no space left on device'));
$rules([['POST', '#^/build\?#', 500, 'raw' => '']]);
check('fleet: a build Docker refuses is reported', str_contains($throws(fn() => $f->update('main', $prog)), 'The build failed: HTTP 500'));
// an instance that does not come up: back on the previous image, the update stops
$rules([$buildOk, ['GET', '#^/containers/talixio-alice/json#', 200, ['State' => ['Status' => 'exited']]]]);
$dlog(); $tp = [];
$e = $throws(fn() => $f->update('main', $prog));
$lg = $dlog();
check('fleet: an instance failing on the new version rolls back and stops the update', str_contains($e, 'alice did not start on the new version') && $has($lg, 'POST', '#^/images/talixio%3Aprevious/tag\?repo=talixio&tag=current#')
    && in_array('failed|alice did not start correctly on the new version: it is back on the previous one, and the update stopped.', $tp, true), $e);
// the admin page cannot be made from the new image: it comes back on its old one
$rules([$buildOk, ['GET', '#^/containers/talixio-admin/json#', 200, $admin], ['POST', '#^/containers/create\?name=talixio-admin#', 500, ['message' => 'bad image'], 'once' => 'adm1']]);
$dlog();
$e = $throws(fn() => $f->update('main', $prog));
$lg = $dlog();
$re = array_values(array_filter($lg, fn($x) => str_starts_with($x['p'], '/containers/create?name=talixio-admin')));
check('fleet: an admin page that fails on the new image is put back on its own', str_contains($e, 'create talixio-admin: bad image') && count($re) === 2 && (json_decode($re[1]['b'], true)['Image'] ?? '') === 'sha256:old', $e);

// rollback
$rules([['GET', '#^/images/talixio%3Aprevious/json#', 404, []]]);
check('fleet: no previous version, no rollback', str_contains($throws(fn() => $f->rollback($prog)), 'No previous version'));
$rules([['GET', '#^/images/talixio%3Aprevious/json#', 200, ['Id' => 'sha256:prev']], ['GET', '#^/images/talixio%3Acurrent/json#', 200, ['Id' => 'sha256:new']],
    ['GET', '#^/containers/talixio-(admin|agent)/json#', 404, []]]);
$dlog(); $tp = [];
$f->rollback($prog);
$lg = $dlog();
check('fleet: rollback swaps current and previous and moves every instance back', $has($lg, 'POST', '#^/images/sha256%3Aprev/tag\?repo=talixio&tag=current#') && $has($lg, 'POST', '#^/images/sha256%3Anew/tag\?repo=talixio&tag=previous#')
    && in_array('rolling|Moving alice back…', $tp, true) && end($tp) === 'done|Back on the previous version.', json_encode($tp));

// check for an update: what runs, against the branch's last commit and its changelog
$rules([['POST', '#^/containers/talixio-alice/exec#', 201, ['Id' => 'ev1'], 'body' => '#VERSION\.json#'], ['POST', '#^/containers/talixio-alice/exec#', 201, ['Id' => 'ev2'], 'body' => '#VERSION"#'],
    ['POST', '#^/exec/ev1/start#', 200, 'frames' => ['{"sha":"1111111111111111111111111111111111111111","date":"2026-01-01","subject":"Release 1.0.0"}']],
    ['POST', '#^/exec/ev2/start#', 200, 'frames' => ["1.0.0\n"]], ['GET', '#^/containers/talixio-erin/json#', 404, []]]);
$cu = $f->checkUpdate('main');
$head = trim((string)$git('rev-parse', 'HEAD'));
check('fleet: check-update reads what runs and the newer changelog entries', $cu['new'] === true && $cu['current']['number'] === '1.0.0' && $cu['latest']['sha'] === $head && $cu['latest']['number'] === '1.2.0'
    && $cu['latest']['subject'] === 'Release 1.2.0' && str_contains($cu['changes'], '## 1.2.0') && str_contains($cu['changes'], '## 1.1.0') && !str_contains($cu['changes'], '## 1.0.0') && !isset($cu['latest']['changelog']), json_encode($cu));
check('fleet: the branch is fetched again only when it moved', is_file("$d/st/latest.json") && $f->checkUpdate('main')['latest']['number'] === '1.2.0');
check('fleet: an unknown branch is said so', str_contains($throws(fn() => $f->checkUpdate('nope')), 'No branch nope'));
putenv("TALIXIO_GIT_URL=file://$d/no-repo");
check('fleet: an unreachable repository is said so', str_contains($throws(fn() => $f->checkUpdate('main')), 'Could not fetch the code'));
putenv("TALIXIO_GIT_URL=$url");

// an update where the agent itself cannot be made from the new image: it stays on the old one, Caddy refusing is only logged
@mkdir("$d/caddy2"); putenv("CADDY_CONFIG_DIR=$d/caddy2");
$agentC = ['Id' => '0123456789abcdef', 'Name' => '/talixio-agent', 'Image' => 'sha256:older'] + array_diff_key($admin, ['Id' => 1, 'Name' => 1, 'Image' => 1]);
$rules([$buildOk, ['GET', '#^/containers/talixio-admin/json#', 404, []], ['GET', '#^/containers/talixio-agent/json#', 200, $agentC],
    ['POST', '#^/containers/create\?name=talixio-agent#', 500, ['message' => 'bad agent image']],
    ['POST', '#^/containers/talixio-caddy/exec#', 201, ['Id' => 'ecad2']], ['GET', '#^/exec/ecad2/json#', 200, ['ExitCode' => 1]]]);
$dlog(); $tp = [];
$el = ini_set('error_log', "$d/php-errors.log");
$f->update('main', $prog);
ini_set('error_log', $el === false ? '' : $el);
$lg = $dlog();
check('fleet: an agent that cannot move stays on the previous version, under its own name', str_contains((string)end($tp), 'The agent stayed on the previous version (create talixio-agent: bad agent image')
    && $has($lg, 'POST', '#^/containers/talixio-agent/rename\?name=talixio-agent-old#') && $has($lg, 'POST', '#^/containers/talixio-agent-old/rename\?name=talixio-agent#')
    && str_contains((string)@file_get_contents("$d/php-errors.log"), 'caddy reload: Caddy refused'), json_encode($tp));
$rules([$buildOk, ['GET', '#^/containers/talixio-admin/json#', 404, []], ['GET', '#^/containers/talixio-agent/json#', 200, $agentC],
    ['POST', '#^/containers/talixio-agent/rename#', 409, ['message' => 'name in use']]]);
check('fleet: an agent that cannot be renamed stops the move', str_contains($throws(fn() => $f->update('main', $prog)), 'rename the agent: name in use'));
putenv("CADDY_CONFIG_DIR=$d/no-caddy");
// the first update (no image before): an instance that fails still stops the update.
// (Not asserted: with no previous image, Fleet.php:665 still records "talixio:previous", because a 404 body is an array too.)
$rules([$buildOk, ['GET', '#^/images/talixio%3Acurrent/json#', 404, ['message' => 'No such image: talixio:current']], ['GET', '#^/containers/talixio-alice/json#', 200, ['State' => ['Status' => 'exited']]]]);
$e = $throws(fn() => $f->update('main', $prog));
check('fleet: on a first update, a failing instance stops it', str_contains($e, 'alice did not start'), $e);
$rules();

// a slow copy: the transfer is followed until it ends, then the assistant until it is healthy
$rules([['GET', '#^/containers/talixio-transfer-erin/json#', 200, ['State' => ['Status' => 'running']], 'once' => 't1'],
    ['GET', '#^/containers/talixio-transfer-erin/json#', 200, ['State' => ['Status' => 'exited', 'ExitCode' => 0]]],
    ['GET', '#^/containers/talixio-transfer-erin/logs#', 200, 'frames' => ["Copying the database\n"]],
    ['GET', '#^/containers/talixio-erin/json#', 200, ['State' => ['Status' => 'running', 'Health' => ['Status' => 'starting']]], 'once' => 'h1']]);
$tp = [];
$f->transferIn('erin', 'Erin', 'https://old.acme.example', 'tx-abc', false, $prog);
check('fleet: a slow copy is followed, each new line shown once', count(array_keys($tp, 'copying|Copying the database', true)) === 1 && str_starts_with((string)end($tp), 'done|Copying the database Address:'), json_encode($tp));
$rules();

// what runs: the first running instance, even without VERSION.json
$rules([['GET', '#^/containers/talixio-alice/json#', 200, ['State' => ['Status' => 'exited']]],
    ['POST', '#^/containers/talixio-erin/exec#', 201, ['Id' => 'ev3'], 'body' => '#VERSION\.json#'], ['GET', '#^/exec/ev3/json#', 200, ['ExitCode' => 1]],
    ['POST', '#^/containers/talixio-erin/exec#', 201, ['Id' => 'ev4'], 'body' => '#VERSION"#'], ['POST', '#^/exec/ev4/start#', 200, 'frames' => ["1.1.0\n"]]]);
$cu = $f->checkUpdate('main');
check('fleet: check-update skips stopped instances and copes without VERSION.json', $cu['current'] === ['sha' => '', 'date' => '', 'subject' => '', 'number' => '1.1.0'] && $cu['new'] === true
    && str_contains($cu['changes'], '## 1.2.0') && !str_contains($cu['changes'], '## 1.1.0'), json_encode($cu));
$rules();

// certificates and routes
$f->setSettings(['mode' => 'subdomain', 'domain' => 'team.acme.example']);
check('fleet: certificates only for existing instances in subdomain mode', $f->ask('alice.team.acme.example') && !$f->ask('zed.team.acme.example') && !$f->ask('alice.other.example'));
$cf = $f->caddyfile();
putenv('TALIXIO_BEHIND_PROXY=1'); $cfp = $f->caddyfile(); putenv('TALIXIO_BEHIND_PROXY');
check('fleet: subdomains get on-demand certificates, or plain HTTP behind another proxy', str_contains($cf, "*.team.acme.example {") && str_contains($cf, 'ask http://talixio-agent:8090/ask')
    && str_contains($cfp, 'auto_https off') && str_contains($cfp, "@alice host alice.team.acme.example") && !str_contains($cfp, '*.team.acme.example'));
$f->setSettings(['mode' => 'path', 'domain' => 'localhost']);
check('fleet: path mode: no certificate on demand; a bad domain is refused', !$f->ask('alice.localhost') && str_contains($f->caddyfile(), 'redir @alice-bare /alice/')
    && str_contains($throws(fn() => $f->setSettings(['domain' => 'not a domain'])), 'Give a domain name'));

// a job whose process is gone is marked interrupted; one just started is left alone
file_put_contents("$d/st/job.json", json_encode(['kind' => 'update', 'state' => 'running', 'pid' => getmypid(), 'host' => gethostname(), 'log' => ['10:00:00 Fetching main…']]));
$j1 = $f->job();
file_put_contents("$d/st/job.json", json_encode(['kind' => 'update', 'state' => 'running', 'pid' => 0, 'updated' => time()]));
$j2 = $f->job();
check('fleet: a job whose process is gone is interrupted, a job just started is running', $j1['state'] === 'failed' && $j1['phase'] === 'interrupted' && count($j1['log']) === 2
    && json_decode((string)file_get_contents("$d/st/job.json"), true)['state'] === 'running' && $j2['state'] === 'running');

// the job file
file_put_contents("$d/st/job.json", json_encode(['kind' => 'update', 'state' => 'done']));
check('fleet: a finished job is shown as it is', $f->job()['state'] === 'done');
@unlink("$d/st/job.json");

// ---------- the admin page in front of the real agent ----------
$serve = function (string $script, array $env, string $log) use ($d): int {
    $port = free_port();
    if (getenv('PHP_INI_SCAN_DIR') !== false) $env += ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')];
    // php -S skips auto_prepend_file for its router: a small router includes it (coverage runs), then the script
    $router = "$d/router-" . basename($script);
    file_put_contents($router, "<?php\nif (\$x = ini_get('auto_prepend_file')) require_once \$x;\nreturn require " . var_export($script, true) . ";\n");
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $d, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pp, null,
        $env + ['PATH' => getenv('PATH'), 'HOME' => getenv('HOME') ?: '/root']);
    register_shutdown_function(function () use ($p) { proc_terminate($p); proc_close($p); });
    wait_port($port);
    return $port;
};
$token = str_repeat('k', 40);
$agentPort = $serve("$root/deploy/docker/agent.php", ['TALIXIO_STATE_DIR' => "$d/ast", 'DOCKER_SOCKET' => $sock, 'TALIXIO_AGENT_TOKEN' => $token, 'TALIXIO_GIT_URL' => $url, 'CADDY_CONFIG_DIR' => "$d/no-caddy"], "$d/agent.log");
$adminEnv = ['TALIXIO_ADMIN_DIR' => "$d/adm", 'TALIXIO_AGENT_URL' => "http://127.0.0.1:$agentPort", 'TALIXIO_AGENT_TOKEN' => $token];
$adminPort = $serve("$root/deploy/docker/admin.php", $adminEnv, "$d/admin.log");
$client = function (int $port) use ($d) {
    return function (string $path, ?array $body = null, string $ip = '203.0.113.7', array $headers = ['X-Talixio: 1'], string $method = '') use ($port, $d) {
        $ch = curl_init("http://127.0.0.1:$port$path");
        $hdr = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => "$d/ck-admin", CURLOPT_COOKIEFILE => "$d/ck-admin", CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', "X-Forwarded-For: $ip"], $headers),
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($h); }]
            + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)] : []) + ($method !== '' ? [CURLOPT_CUSTOMREQUEST => $method] : []));
        $raw = (string)curl_exec($ch);
        $out = json_decode($raw, true); $out = is_array($out) ? $out : [];
        $out['_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE); $out['_raw'] = $raw; $out['_headers'] = $hdr;
        curl_close($ch);
        return $out;
    };
};
$a = $client($adminPort);
$rules();
// the setup code, printed at start
$bp = proc_open([PHP_BINARY, "$root/deploy/docker/admin.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $bpp, null, $adminEnv + getenv());
$bootErr = stream_get_contents($bpp[2]); fclose($bpp[1]); fclose($bpp[2]); proc_close($bp);
$conf = fn() => json_decode((string)@file_get_contents("$d/adm/admin.json"), true) ?: [];
$saveConf = fn(array $c) => file_put_contents("$d/adm/admin.json", json_encode($c));
$setup = (string)($conf()['setup_code'] ?? '');
check('admin: at start a one-time setup code is made and shown', strlen($setup) === 12 && str_contains($bootErr, "setup code: $setup"));
$h = $a('/');
check('admin: the page carries its base and strict headers', $h['_code'] === 200 && str_contains($h['_raw'], '<head><base href="/">') && $h['_headers']['x-frame-options'] === 'DENY' && str_contains($h['_headers']['content-security-policy'], "default-src 'self'"));
$js = $a('/admin.js');
check('admin: static files with their types', $js['_code'] === 200 && str_starts_with($js['_headers']['content-type'], 'text/javascript') && $js['_raw'] !== '');
$me = $a('/api/me');
check('admin: not configured, not signed in', $me['configured'] === false && $me['signed_in'] === false);
check('admin: a POST without the request header is refused', $a('/api/setup', ['setup_code' => $setup], '203.0.113.7', [])['_code'] === 403);
check('admin: no sign-in before setup', $a('/api/login', ['password' => 'x'])['error'] === 'Not set up yet.');
@unlink("$d/ck-admin"); // a new browser: no secret was shown to it
check('admin: setup cannot finish without the secret shown first', str_contains($a('/api/setup', ['setup_code' => $setup, 'password' => 'correct horse battery', 'code' => '123 456'])['error'] ?? '', 'That code is not right'));
file_put_contents("$d/adm/throttle.json", json_encode(['192.0.2.1' => [time() - 90000], '192.0.2.2' => [time() - 60]]));
$w = $a('/api/setup', ['setup_code' => 'WRONG']);
check('admin: a wrong setup code is refused', $w['_code'] === 401 && str_contains($w['error'], 'Wrong setup code'));
$th = json_decode((string)file_get_contents("$d/adm/throttle.json"), true);
check('admin: failures older than a day are forgotten', !isset($th['192.0.2.1']) && isset($th['192.0.2.2']) && count($th['203.0.113.7']) === 1);
check('admin: address lists: exact addresses, prefixes not on a byte, families apart', AdminWeb::ipAllowed('203.0.113.7', ['203.0.113.7']) && !AdminWeb::ipAllowed('203.0.113.8', ['203.0.113.7'])
    && AdminWeb::ipAllowed('172.20.1.1', ['172.16.0.0/12']) && !AdminWeb::ipAllowed('172.32.0.1', ['172.16.0.0/12']) && !AdminWeb::ipAllowed('2001:db8::1', ['0.0.0.0/0', 'junk'])
    && AdminWeb::ipAllowed('2001:db8::1', ['2001:db8::/32']));
for ($i = 0; $i < 5; $i++) $a('/api/setup', ['setup_code' => 'WRONG'], '198.51.100.9');
$w = $a('/api/setup', ['setup_code' => $setup], '198.51.100.9');
check('admin: too many attempts from one address are throttled', $w['_code'] === 429 && str_contains($w['error'], 'Too many attempts'), json_encode($w));
$s1 = $a('/api/setup', ['setup_code' => strtolower($setup)]);
check('admin: setup step 1 gives an authenticator secret', strlen($s1['secret'] ?? '') === 32 && str_starts_with($s1['uri'], 'otpauth://totp/Talixio%20admin?secret='));
$secret = $s1['secret'];
$code = fn() => Auth::totp($secret, intdiv(time(), 30));
$freshCode = function () use ($conf, $saveConf, $code) { $c = $conf(); $c['totp_last'] = 0; $saveConf($c); return $code(); };
check('admin: the admin password is long enough', $a('/api/setup', ['setup_code' => $setup, 'password' => 'short', 'code' => $code()])['error'] === 'The password needs at least 12 characters.');
check('admin: a wrong authenticator code is refused', str_contains($a('/api/setup', ['setup_code' => $setup, 'password' => 'correct horse battery', 'code' => '000000x'])['error'] ?? '', 'That code is not right'));
$s2 = $a('/api/setup', ['setup_code' => $setup, 'password' => 'correct horse battery', 'code' => $code()]);
check('admin: setup signs in', ($s2['ok'] ?? false) === true && $a('/api/me')['signed_in'] === true && !empty($conf()['password_hash']) && !isset($conf()['setup_code']), json_encode($s2));
check('admin: setup happens once', $a('/api/setup', ['setup_code' => $setup])['error'] === 'Already set up.');
check('admin: a code is used once', $a('/api/password', ['current' => 'correct horse battery', 'code' => $code(), 'new' => 'another long password'])['error'] === 'Wrong current password or code.');

// actions through the agent
$cr = $a('/api/instances', ['id' => 'frank', 'name' => 'Frank', 'email' => 'frank@acme.example', 'memory_mb' => 768, 'evil' => '--privileged']);
check('admin: a new person through the agent, with a first password', ($cr['id'] ?? '') === 'frank' && strlen($cr['password'] ?? '') === 20 && str_ends_with($cr['url'], '/frank/'), json_encode($cr));
$bad = $a('/api/instances', ['id' => 'admin']);
check('admin: the agent refusing an identifier is a 400 with its reason', $bad['_code'] === 400 && str_contains($bad['error'], 'identifier'), json_encode($bad));
$stt = $a('/api/state');
check('admin: the state gathers instances, server and job', $stt['_code'] === 200 && $stt['instances'][0]['id'] === 'frank' && isset($stt['server']['sizing']) && $stt['job']['state'] === 'none'
    && $stt['my_ip'] === '203.0.113.7' && $stt['settings']['eu_key'] === false && isset($stt['version']), substr($stt['_raw'], 0, 300));
$rules([['GET', '#^/containers/talixio-frank/logs#', 200, 'frames' => ["2026-10-14T08:00:02.5Z PHP Warning: odd\n"]]]);
$lgs = $a('/api/instances/frank/logs', ['all' => true]);
check('admin: the logs of an instance', $lgs['text'] === '2026-10-14T08:00:02Z PHP Warning: odd', json_encode($lgs));
$rules();
check('admin: simple actions go through', ($a('/api/instances/frank/restart', [])['ok'] ?? false) === true && strlen($a('/api/instances/frank/reset-password', [])['password'] ?? '') === 20
    && ($a('/api/instances/frank/limits', ['memory_mb' => 2048, 'cpus' => 2])['ok'] ?? false) === true);
$lk = $a('/api/instances/frank/links', ['links' => ['nobody']]);
check('admin: links to an unknown assistant are refused with the reason', $lk['_code'] === 400 && str_contains($lk['error'], 'no instance nobody'), json_encode($lk));
$rules([['POST', '#^/containers/talixio-frank/stop#', 500, ['message' => 'daemon error']]]);
$st5 = $a('/api/instances/frank/stop', []);
check('admin: an agent failure is a 502 with its reason', $st5['_code'] === 502 && str_contains($st5['error'], 'daemon error'), json_encode($st5));
$rules();
check('admin: removing needs the identifier typed again', str_contains($a('/api/instances/frank/remove', ['confirm' => 'nope'])['error'] ?? '', 'Type the identifier (frank)'));
$se = $a('/api/settings', ['domain' => 'team.acme.example', 'eu_key' => 'sk-hidden', 'shell' => 'x']);
check('admin: settings go to the agent; secrets do not come back', ($se['settings']['domain'] ?? '') === 'team.acme.example' && $se['settings']['eu_key'] === true && $se['recreated'] === true, json_encode($se));
check('admin: an address that is not one is refused', str_contains($a('/api/allow-ips', ['list' => '10.0.0.0/8, nonsense'])['error'] ?? '', 'Not an address: nonsense'));
check('admin: a list without my own address would lock me out', str_contains($a('/api/allow-ips', ['list' => '10.0.0.0/8'])['error'] ?? '', 'you would lock yourself out'));
$al = $a('/api/allow-ips', ['list' => "203.0.113.0/24\n2001:db8::/32"]);
check('admin: allowed addresses are saved', $al['allow_ips'] === ['203.0.113.0/24', '2001:db8::/32'] && $conf()['allow_ips'] === ['203.0.113.0/24', '2001:db8::/32']);
$out = $a('/api/me', null, '198.51.100.20');
check('admin: another address is turned away', $out['_code'] === 403 && $out['_raw'] === 'Not allowed from this address.');
$a('/api/allow-ips', ['list' => '']);
$rm = $a('/api/instances/frank/remove', ['confirm' => 'frank', 'delete_data' => true]);
check('admin: remove with the identifier confirmed', ($rm['ok'] ?? false) === true && $a('/api/state')['instances'] === []);
$cu = $a('/api/check-update?branch=main');
check('admin: check for an update through the agent', ($cu['latest']['number'] ?? '') === '1.2.0' && $cu['current'] === null && $cu['new'] === true, substr($cu['_raw'], 0, 300));
// long actions: run by fleet-job.php, followed in job.json
$waitJob = function () use ($a) { for ($i = 0; $i < 100; $i++) { $j = $a('/api/job'); if (($j['state'] ?? '') !== 'running') return $j; usleep(100000); } return $j; };
$rules([['POST', '#^/containers/talixio-ollama/exec#', 201, ['Id' => 'epull2']]]);
check('admin: a model download starts', ($a('/api/pull-model', ['model' => 'all-minilm'])['ok'] ?? false) === true);
$j = $waitJob();
check('admin: the model download finishes in the job', $j['state'] === 'done' && $j['message'] === 'all-minilm ready' && $j['kind'] === 'pull-model', json_encode($j));
$rules([['GET', '#^/images/talixio%3Aprevious/json#', 404, []]]);
$a('/api/rollback', []);
$j = $waitJob();
check('admin: a rollback without previous version fails in the job', $j['state'] === 'failed' && str_contains($j['message'], 'No previous version'), json_encode($j));
$a('/api/update', ['branch' => 'bad branch']);
$j = $waitJob();
check('admin: an update with a bad branch fails in the job', $j['state'] === 'failed' && $j['message'] === 'bad branch name', json_encode($j));
$tr = $a('/api/transfer', ['id' => 'gina', 'source' => 'https://old.acme.example', 'code' => 'nope']);
check('admin: a transfer needs the old address and its code', $tr['_code'] === 400 && str_contains($tr['error'], 'transfer code'));
$rules([['GET', '#^/containers/talixio-transfer-gina/json#', 200, ['State' => ['Status' => 'exited', 'ExitCode' => 0]]], ['GET', '#^/containers/talixio-transfer-gina/logs#', 200, 'frames' => ["Done.\n"]]]);
check('admin: a transfer starts', ($a('/api/transfer', ['id' => 'gina', 'name' => 'Gina', 'source' => 'https://old.acme.example', 'code' => 'tx-' . str_repeat('a', 40), 'final' => true])['ok'] ?? false) === true);
$j = $waitJob();
check('admin: the transfer ends with the address', $j['state'] === 'done' && str_contains($j['message'], 'Done. Address: https://team.acme.example/gina/'), json_encode($j));
$rules();
$au = $a('/api/audit');
$whats = array_column($au['entries'] ?? [], 'what');
check('admin: every action is in the audit log, newest first', ($whats[0] ?? '') === 'transfer in' && in_array('instance created', $whats, true) && in_array('settings changed', $whats, true)
    && in_array('allowed addresses changed', $whats, true) && in_array('admin set up', $whats, true) && in_array('pull-model', $whats, true), json_encode($whats));
check('admin: unknown routes are 404', $a('/api/nothing')['_code'] === 404);
// password change, sessions of the old password end
check('admin: the new admin password is long enough', $a('/api/password', ['current' => 'correct horse battery', 'code' => $freshCode(), 'new' => 'short'])['error'] === 'The password needs at least 12 characters.');
$pc = $a('/api/password', ['current' => 'correct horse battery', 'code' => $freshCode(), 'new' => 'another long password']);
check('admin: the password changes and signs everyone out', ($pc['ok'] ?? false) === true && $a('/api/state')['error'] === 'Please sign in.');
$lf = $a('/api/login', ['password' => 'correct horse battery', 'code' => $freshCode()]);
check('admin: the old password no longer signs in', $lf['_code'] === 401 && $lf['error'] === 'Wrong password or code.');
$lo = $a('/api/login', ['password' => 'another long password', 'code' => $freshCode()]);
check('admin: sign in with password and code', ($lo['ok'] ?? false) === true && $a('/api/me')['signed_in'] === true);
// the agent out of reach
$b = $client($serve("$root/deploy/docker/admin.php", ['TALIXIO_AGENT_URL' => 'http://127.0.0.1:' . free_port()] + $adminEnv, "$d/admin2.log"));
$un = $b('/api/job');
check('admin: an agent out of reach is a 502', $un['_code'] === 502 && str_contains($un['error'], 'The agent is not reachable'), json_encode($un));
file_put_contents("$d/fake-agent.php", "<?php\nif (\$_SERVER['REQUEST_URI'] === '/job') { echo '5'; return; }\nhttp_response_code(503); echo 'down';\n");
$fakeAgentPort = $serve("$d/fake-agent.php", [], "$d/fake-agent.log");
$b3 = $client($serve("$root/deploy/docker/admin.php", ['TALIXIO_AGENT_URL' => "http://127.0.0.1:$fakeAgentPort"] + $adminEnv, "$d/admin3.log"));
$x1 = $b3('/api/state'); $x2 = $b3('/api/job');
check('admin: an agent error without a message, and an answer that is not an object', $x1['_code'] === 502 && $x1['error'] === 'agent error 503' && $x2['_code'] === 500 && $x2['error'] !== '', json_encode([$x1['error'] ?? '', $x2['error'] ?? '']));
check('admin: sign out', ($a('/api/logout', [])['ok'] ?? false) === true && $a('/api/me')['signed_in'] === false);
for ($i = 0; $i < 5; $i++) $a('/api/login', ['password' => 'wrong', 'code' => '123456'], '198.51.100.30');
check('admin: sign-in attempts are throttled too', $a('/api/login', ['password' => 'another long password', 'code' => $freshCode()], '198.51.100.30')['_code'] === 429);
check('admin: a boot after setup prints nothing', (function () use ($d) { $x = new AdminWeb("$d/adm", 'http://127.0.0.1:1', 't'); $x->boot(); return !isset(json_decode((string)file_get_contents("$d/adm/admin.json"), true)['setup_code']); })());

// ---------- Sign in with Claude (a fake `claude setup-token`) ----------
$cbin = "$d/claude-bin";
@mkdir($cbin);
$fakeClaude = fn(string $after) => "#!/bin/sh\nprintf 'Browser did not open? Use the url below to sign in:\\n\\n\\033[1mhttps://claude.ai/oauth/authorize?code=true&client_id=demo&state=xyz\\033[0m\\n\\nPaste code here if prompted > '\nread code\n$after";
file_put_contents("$cbin/claude-ok", $fakeClaude("if [ \"\$code\" = good-code ]; then printf '\\nLong-lived token created:\\n\\033[32msk-ant-oat01-DemoToken_abcdefghijklmnopqrstuvwxyz\\033[0m\\n'; else printf '\\nOAuth error: Invalid code. Press Enter to retry.\\n'; fi\nsleep 3\n"));
file_put_contents("$cbin/claude-die", "#!/bin/sh\necho 'Error: \\033[31mnot available\\033[0m in this region'\nexit 1\n");
chmod("$cbin/claude-ok", 0755); chmod("$cbin/claude-die", 0755);
$cd = "$d/claude-data";
@mkdir("$cd/claude-login", 0700, true);
putenv("TALIXIO_DATA_DIR=$cd"); putenv('TALIXIO_PROVIDER=echo');
$cfgWith = function (string $bin) { putenv("TALIXIO_CLAUDE_BIN=$bin"); return Config::fromEnv(); };
check('claude: installed means an executable path, or a command on PATH', !ClaudeLogin::installed($cfgWith("$cbin/none")) && ClaudeLogin::installed($cfgWith("$cbin/claude-ok"))
    && ClaudeLogin::installed($cfgWith('sh')) && !ClaudeLogin::installed($cfgWith('no-such-claude-xyz')));
$cstate = fn() => json_decode((string)@file_get_contents("$cd/claude-login/state.json"), true) ?: [];
// the background process, run here: the code is already waiting
file_put_contents("$cd/claude-login/code", 'good-code');
ClaudeLogin::run($cfgWith("$cbin/claude-ok"));
check('claude: the terminal gets the code and the token is kept', $cstate()['state'] === 'done' && (string)@file_get_contents("$cd/claude-login/token") === 'sk-ant-oat01-DemoToken_abcdefghijklmnopqrstuvwxyz' && !is_file("$cd/claude-login/code"));
file_put_contents("$cd/claude-login/code", 'used-code');
ClaudeLogin::run($cfgWith("$cbin/claude-ok"));
check('claude: a refused code says so', $cstate()['state'] === 'error' && $cstate()['message'] === 'Claude refused the code (OAuth error: Invalid code.). Start again and paste the whole code.', json_encode($cstate()));
ClaudeLogin::run($cfgWith("$cbin/claude-die"));
check('claude: Claude Code stopping early is reported with what it said', $cstate()['state'] === 'error' && str_contains($cstate()['message'], 'Claude Code stopped: Error: not available in this region'), json_encode($cstate()));
// from the web app: start, then the code
@unlink("$cd/claude-login/token");
$cfgWith("$cbin/none"); $app = App::fromEnv();
check('claude: not installed, no sign-in', str_contains($throws(fn() => (new ClaudeLogin($app))->start()), 'not installed'));
$cfgWith("$cbin/claude-die"); $app = App::fromEnv();
check('claude: a start that fails says why', str_contains($throws(fn() => (new ClaudeLogin($app))->start()), 'Claude Code stopped'));
check('claude: a code longer than 400 characters is refused', str_contains($throws(fn() => (new ClaudeLogin($app))->finish(str_repeat('a', 401))), 'Paste the code'));
check('claude: a code needs a sign-in waiting', str_contains($throws(fn() => (new ClaudeLogin($app))->finish('abc')), 'expired') && str_contains($throws(fn() => (new ClaudeLogin($app))->finish('a b')), 'Paste the code'));
$cfgWith("$cbin/claude-ok"); $app = App::fromEnv();
foreach (glob("$cd/claude-login/*") as $x) unlink($x); rmdir("$cd/claude-login"); // made again by start
$cl = new ClaudeLogin($app);
$st1 = $cl->start();
check('claude: start shows the sign-in link', $st1['url'] === 'https://claude.ai/oauth/authorize?code=true&client_id=demo&state=xyz', json_encode($st1));
$e = $throws(fn() => $cl->finish('wrong-code'));
check('claude: a wrong code is refused', str_contains($e, 'Claude refused the code'), $e);
$cl->start();
$fin = $cl->finish('good-code');
$saved = Settings::load($cd);
check('claude: the right code switches the assistant to the Claude plan', $fin === ['ok' => true] && ($saved['CLAUDE_CODE_OAUTH_TOKEN'] ?? '') === 'sk-ant-oat01-DemoToken_abcdefghijklmnopqrstuvwxyz'
    && ($saved['TALIXIO_PROVIDER'] ?? '') === 'claude-code' && $cstate()['state'] === 'none' && !is_file("$cd/claude-login/token"));
$fleetRestoreEnv();

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
