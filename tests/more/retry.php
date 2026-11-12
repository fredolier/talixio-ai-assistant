<?php
declare(strict_types=1);
// Audit Q13: model calls are tried again on overload, rate limits and network errors (never once text has reached the
// user), connecting fails fast, and a background job cannot run forever.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Http, Jobs};

$d = test_dir('retry');
file_put_contents("$d/server.php", '<?php
$n = (int)@file_get_contents(__DIR__ . "/count") + 1; file_put_contents(__DIR__ . "/count", (string)$n);
$mode = (string)@file_get_contents(__DIR__ . "/mode");
if ($mode === "busy" && $n <= 2) { http_response_code(529); header("Retry-After: 7"); echo "{\\"error\\":\\"overloaded\\"}"; return; }
if ($mode === "always") { http_response_code(503); echo "{}"; return; }
if ($mode === "sse-busy" && $n === 1) { http_response_code(503); echo "busy"; return; }
if (str_starts_with($mode, "sse")) {
    header("Content-Type: text/event-stream");
    echo "event: message\ndata: {\\"t\\":\\"hello\\"}\n\n"; flush();
    if ($mode === "sse-break") { http_response_code(200); echo "event: error\ndata: {\\"type\\":\\"error\\"}\n\n"; }
    return;
}
header("Content-Type: application/json"); echo "{\\"ok\\":true,\\"n\\":$n}";');
$port = free_port();
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$d/server.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
wait_port($port);
$url = "http://127.0.0.1:$port/v1";
$slept = [];
Http::$sleep = function (int $s) use (&$slept) { $slept[] = $s; };
$mode = function (string $m) use ($d, &$slept) { file_put_contents("$d/mode", $m); @unlink("$d/count"); $slept = []; };
$count = fn() => (int)@file_get_contents("$d/count");

$mode('busy');
$r = Http::json($url, ['x' => 1], [], 30, 3);
check('retry: an overloaded model is asked again, after the wait it asks for', ($r['ok'] ?? false) === true && $count() === 3 && $slept === [7, 7], json_encode([$r, $count(), $slept]));
$mode('busy');
try { Http::json($url, ['x' => 1], [], 30); $err = ''; } catch (RuntimeException $e) { $err = $e->getMessage(); }
check('retry: other calls (a message sent to someone) are not sent twice', str_contains($err, 'API error 529') && $count() === 1);
$mode('always');
try { Http::json($url, ['x' => 1], [], 30, 3); $err = ''; } catch (RuntimeException $e) { $err = $e->getMessage(); }
check('retry: it gives up after its tries, with the error', str_contains($err, 'API error 503') && $count() === 3 && $slept === [2, 4], json_encode([$err, $slept]));
$mode('');
$closed = free_port();
$t = microtime(true);
try { Http::json("http://127.0.0.1:$closed/v1", [], [], 30, 2); $err = ''; } catch (RuntimeException $e) { $err = $e->getMessage(); }
check('retry: a network error is tried again too', str_starts_with($err, 'HTTP error') && $slept === [2] && microtime(true) - $t < 10, json_encode([$err, $slept]));
// streams: tried again before the first event only
$mode('sse-busy'); $got = [];
Http::sse($url, [], [], function ($e, $d) use (&$got) { $got[] = $d['t'] ?? $d['type'] ?? '?'; }, 30, 3);
check('retry: a stream refused at first is asked again', $got === ['hello'] && $count() === 2, json_encode([$got, $count()]));
$mode('sse-break'); $got = [];
try { Http::sse($url, [], [], function ($e, $d) use (&$got) { $got[] = $d['t'] ?? '?'; if (($d['type'] ?? '') === 'error') throw new RuntimeException('API error: stream error'); }, 30, 3); $err = ''; }
catch (RuntimeException $e) { $err = $e->getMessage(); }
check('retry: a stream that already gave text is never replayed', $err === 'API error: stream error' && $count() === 1 && $got === ['hello', '?'], json_encode([$err, $count(), $got]));
Http::$sleep = null;
proc_terminate($srv); proc_close($srv);

// a background job stuck for longer than allowed is stopped
$keep = getenv('TALIXIO_DATA_DIR'); putenv("TALIXIO_DATA_DIR=$d/app"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none');
$app = App::fromEnv(); $app->cfg->jobMaxMinutes = 30;
$jid = $app->store->addJob('stuck', 'x', 'fast', 1.0);
$app->store->updateJob($jid, ['status' => 'running', 'started' => time() - 31 * 60]);
$procs = new ReflectionProperty(Jobs::class, 'procs');
$sleeper = proc_open(['sleep', '30'], [], $sp);
$procs->setValue(null, [$jid => $sleeper]);
$app->jobs()->dispatch();
$j = $app->store->job($jid);
check('retry: a job running past the longest allowed time is stopped and marked failed', $j['status'] === 'failed' && str_contains((string)$j['result'], 'after 30 minutes'), json_encode($j));
usleep(300000); $app->jobs()->dispatch();
check('retry: ... and its process is gone (closed by the worker)', $procs->getValue() === []);
$keep === false ? putenv('TALIXIO_DATA_DIR') : putenv("TALIXIO_DATA_DIR=$keep");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
