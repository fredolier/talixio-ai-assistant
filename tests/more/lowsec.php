<?php
declare(strict_types=1);
// Audit lot 6, low-severity security: addresses others give us (S12), the sign-in pause for everyone (S13).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Auth, Http};

// ---- S12: a paired assistant's or a webhook's address is never inside this server's network
$err = function (string $url): string { try { Http::publicResolve($url); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };
foreach (['https://127.0.0.1/inbox', 'https://localhost:8443/x', 'https://10.0.0.7/x', 'https://169.254.169.254/latest', 'https://[::1]/x', 'https://192.168.1.10/x'] as $u)
    check("lowsec: $u is refused as another person's address", $err($u) !== '', $err($u));
check('lowsec: a public address is pinned to the address checked', isset(Http::publicResolve('https://93.184.216.34/x')[CURLOPT_RESOLVE]) && str_ends_with(Http::publicResolve('https://93.184.216.34/x')[CURLOPT_RESOLVE][0], ':93.184.216.34'));
putenv('TALIXIO_ALLOW_PRIVATE_PEERS=1');
check('lowsec: two assistants on one machine may talk when it is set on purpose', Http::publicResolve('https://127.0.0.1/inbox') === []);
putenv('TALIXIO_ALLOW_PRIVATE_PEERS');
$d = test_dir('lowsec-fed');
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_EMBEDDINGS' => 'off'] as $k => $v) putenv("$k=$v");
$app = App::fromEnv();
$m = new ReflectionMethod($app->federation(), 'call');
try { $m->invoke($app->federation(), 'POST', 'https://127.0.0.1:9/api/federation/push', 'secret', ['x' => 1]); $ferr = ''; } catch (\Throwable $e) { $ferr = $e->getMessage(); }
check('lowsec: the federation refuses to call a peer inside the network', $ferr !== '' && !str_contains($ferr, 'Connection refused'), $ferr);
putenv('TALIXIO_DATA_DIR');

// ---- S13: many failed sign-ins from many addresses pause sign-in, but not for the person
$dw = test_dir('lowsec-web');
$web0 = web_app($dw, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery']);
$port = (int)(new ReflectionFunction($web0))->getStaticVariables()['port'];
// one X-Forwarded-For per request (web_app adds its own, and the PHP test server keeps the first one)
$web = function (string $path, array $body, string $jar, string $m, array $headers) use ($port, $dw): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_COOKIEJAR => "$dw/ck-$jar", CURLOPT_COOKIEFILE => "$dw/ck-$jar",
        CURLOPT_HTTPHEADER => array_merge(['X-Talixio: 1', 'Content-Type: application/json'], $headers), CURLOPT_TIMEOUT => 20]);
    $out = json_decode((string)curl_exec($ch), true) ?: []; $out['_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $out;
};
$from = fn(string $ip) => ['X-Forwarded-For: ' . $ip];
check('lowsec: the owner signs in from home first', $web('/api/login', ['password' => 'correct horse battery'], 'home', '', $from('203.0.113.50'))['_code'] === 200);
for ($i = 0; $i < Auth::MAX_FAILS_ALL; $i++) $web('/api/login', ['password' => "guess $i"], "bot$i", '', $from('198.51.100.' . ($i % 200 + 1)));
$r = $web('/api/login', ['password' => 'another guess'], 'bot-x', '', $from('198.51.100.250'));
check('lowsec: after many failures from many addresses, sign-in pauses', $r['_code'] === 429, (string)$r['_code']);
$r = $web('/api/login', ['password' => 'correct horse battery'], 'stranger', '', $from('198.51.100.251'));
check('lowsec: during the pause, the right password from an unknown address still waits (it reveals nothing)', $r['_code'] === 429 && str_contains($r['error'] ?? '', 'Too many'), json_encode($r));
$r = $web('/api/login', ['password' => 'correct horse battery'], 'home', '', $from('203.0.113.50'));
check('lowsec: ... but the person, from an address they signed in from, gets in', $r['_code'] === 200, json_encode($r));
$r = $web('/api/login', ['password' => 'wrong'], 'home2', '', $from('203.0.113.50'));
check('lowsec: ... and only with the right password', $r['_code'] === 401 || $r['_code'] === 429, (string)$r['_code']);
// ---- S17: an app waiting for approval does not run, even opened directly
putenv("TALIXIO_DATA_DIR=$dw"); putenv('TALIXIO_WEB_PASSWORD=correct horse battery');
$wa = App::fromEnv();
$pa = $wa->apps()->save(['name' => 'Waiting app', 'html' => '<p>hi</p>', 'script' => "talixio.download('x.txt', 'boo');"], 'assistant');
putenv('TALIXIO_DATA_DIR'); putenv('TALIXIO_WEB_PASSWORD');
check('lowsec: an app made by the assistant waits for approval', $pa['status'] === 'pending');
$ch = curl_init("http://127.0.0.1:$port/api/apps/{$pa['id']}/frame"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => "$dw/ck-home", CURLOPT_HTTPHEADER => ['X-Forwarded-For: 203.0.113.50']]);
$body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
check('lowsec: its frame is not served before approval, so its code never runs', $code === 403 && !str_contains($body, 'talixio.download'), "$code $body");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
