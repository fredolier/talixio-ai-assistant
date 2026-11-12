<?php
declare(strict_types=1);
// Audit Q10, Q12: state kept in JSON files is written whole or not at all, and updated under a lock.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, JsonFile};

$d = test_dir('jsonfile');
$f = "$d/state.json";
JsonFile::write($f, ['n' => 1, 'name' => 'café']);
check('jsonfile: written and read back', JsonFile::read($f) === ['n' => 1, 'name' => 'café'] && (fileperms($f) & 0777) === 0600);
JsonFile::$failHook = function () { throw new RuntimeException('disk full'); };
try { JsonFile::write($f, ['n' => 2]); $err = ''; } catch (RuntimeException $e) { $err = $e->getMessage(); }
JsonFile::$failHook = null;
check('jsonfile: a failed write leaves the old file whole and says so', $err === 'disk full' && JsonFile::read($f) === ['n' => 1, 'name' => 'café'] && glob("$f.tmp-*") === []);
try { JsonFile::write($f, ['bad' => "\xB1\x31"]); $err = ''; } catch (JsonException $e) { $err = 'json'; }
check('jsonfile: data that cannot be encoded is refused, the file kept', $err === 'json' && JsonFile::read($f)['n'] === 1);
check('jsonfile: a missing or broken file reads as empty', JsonFile::read("$d/none.json") === [] && (file_put_contents("$d/broken.json", '{"a":') && JsonFile::read("$d/broken.json") === []));
check('jsonfile: an update that returns no data writes nothing', JsonFile::update($f, fn($s) => [null, 'kept']) === 'kept' && JsonFile::read($f)['n'] === 1);
// 6 processes add 25 each to a counter at the same time: none is lost
$c = "$d/counter.json"; JsonFile::write($c, ['n' => 0]);
$code = 'require ' . var_export(__DIR__ . '/../../src/autoload.php', true) . '; for ($i = 0; $i < 25; $i++) Talixio\JsonFile::update(' . var_export($c, true) . ', fn($s) => [["n" => ($s["n"] ?? 0) + 1], null]);';
$ps = []; for ($i = 0; $i < 6; $i++) $ps[] = proc_open([PHP_BINARY, '-r', $code], [], $pp);
foreach ($ps as $p) proc_close($p);
check('jsonfile: concurrent updates are all kept', JsonFile::read($c)['n'] === 150, (string)(JsonFile::read($c)['n'] ?? 'none'));

// the pilot's binding: a pairing code pairs once, also when two arrive together
$keep = getenv('TALIXIO_DATA_DIR');
putenv("TALIXIO_DATA_DIR=$d/app"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none');
$app = App::fromEnv(); $con = $app->conductor();
$setup = $con->setup(); preg_match('/"code":"([a-f0-9]{32})"/', $setup, $m);
[$c1] = $con->inbox(['type' => 'pair', 'code' => $m[1], 'mode' => 'poll'], '');
[$c2] = $con->inbox(['type' => 'pair', 'code' => $m[1], 'mode' => 'poll'], '');
check('jsonfile: a pairing code is used once', $c1 === 200 && $c2 === 403, "$c1 $c2");
check('jsonfile: the binding is private', (fileperms("$d/app/conductor.json") & 0777) === 0600);
$con->disconnect();
check('jsonfile: disconnecting removes the binding', !is_file("$d/app/conductor.json") && !$con->connected());
// settings and the pilot's policy: changes made at the same time are all kept; a failed write keeps the old settings
$fields = [['TALIXIO_NAME', 'Robin'], ['TALIXIO_HEARTBEAT_MINUTES', '45'], ['TALIXIO_TIMEZONE', 'Europe/Paris']];
$lost = 0;
for ($round = 0; $round < 5; $round++) { // three processes each save one setting, all at the same instant
    $sd = "$d/settings-$round"; @mkdir($sd); $at = microtime(true) + 0.4;
    $ps = [];
    foreach ($fields as [$k, $v]) {
        $code = 'require ' . var_export(__DIR__ . '/../../src/autoload.php', true) . '; while (microtime(true) < ' . $at . ') usleep(500); Talixio\Settings::save(' . var_export($sd, true) . ', [' . var_export($k, true) . ' => ' . var_export($v, true) . ']);';
        $ps[] = proc_open([PHP_BINARY, '-r', $code], [], $pp);
    }
    foreach ($ps as $p) proc_close($p);
    $st = Talixio\Settings::load($sd);
    foreach ($fields as [$k, $v]) if (($st[$k] ?? '') !== $v) $lost++;
}
check('jsonfile: settings saved by several processes at the same instant are all kept', $lost === 0, "$lost lost");
Talixio\Settings::save($sd, ['TALIXIO_NAME' => 'Kept']);
JsonFile::$failHook = function () { throw new RuntimeException('disk full'); };
try { Talixio\Settings::save($sd, ['TALIXIO_NAME' => 'Lost']); } catch (RuntimeException) {}
JsonFile::$failHook = null;
check('jsonfile: a failed settings write keeps the previous settings', Talixio\Settings::load($sd)['TALIXIO_NAME'] === 'Kept');
$pi = $app->pilot();
$pi->setPolicy(['rules' => ['Never touch billing']]);
check('jsonfile: the pilot\'s policy is kept privately', in_array('Never touch billing', $pi->rules(), true) && (fileperms("$d/app/pilot.json") & 0777) === 0600);
// the sign-in tokens of Google, Microsoft and Spotify: a failed write keeps the ones that work
foreach (['google' => $app->google(), 'microsoft' => $app->microsoft(), 'spotify' => $app->spotify()] as $what => $svc) {
    $save = new ReflectionMethod($svc, 'saveTokens');
    $save->invoke($svc, ['access_token' => "good-$what", 'refresh_token' => 'r']);
    JsonFile::$failHook = function () { throw new RuntimeException('disk full'); };
    try { $save->invoke($svc, ['access_token' => 'half']); } catch (RuntimeException) {}
    JsonFile::$failHook = null;
    check("jsonfile: a failed write keeps the $what sign-in", ($svc->tokens()['access_token'] ?? '') === "good-$what");
}
$keep === false ? putenv('TALIXIO_DATA_DIR') : putenv("TALIXIO_DATA_DIR=$keep");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
