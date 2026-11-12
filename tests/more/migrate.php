<?php
declare(strict_types=1);
// Audit Q2: the database is opened by the web app, the worker and job processes at the same time; its migration must not
// fail when several open a new or an old database together, and must not run again when the schema is up to date.
require_once __DIR__ . '/../helpers.php';

$d = test_dir('migrate');
$open = function (string $db, int $n): array {
    $code = 'require ' . var_export(__DIR__ . '/../../src/autoload.php', true) . '; try { new Talixio\Store(' . var_export($db, true) . '); echo "ok"; } catch (Throwable $e) { echo "ERR ", $e->getMessage(); }';
    $procs = [];
    for ($i = 0; $i < $n; $i++) $procs[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    $out = [];
    foreach ($procs as $i => $p) { $out[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2])); proc_close($p); }
    return $out;
};
foreach ([1, 2, 3] as $round) {
    $fresh = "$d/fresh-$round.db";
    $r = $open($fresh, 8);
    check("migrate: 8 processes opening a new database together all succeed (round $round)", array_unique($r) === ['ok'], implode(' | ', array_filter($r, fn($x) => $x !== 'ok')));
    $old = "$d/old-$round.db";
    $pdo = new PDO("sqlite:$old"); $pdo->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY, role TEXT NOT NULL, channel TEXT NOT NULL, content TEXT NOT NULL, ts INTEGER NOT NULL); INSERT INTO messages(role, channel, content, ts) VALUES ("user", "web", "hello", 1)'); $pdo = null;
    $r = $open($old, 8);
    check("migrate: 8 processes opening an old database together all succeed (round $round)", array_unique($r) === ['ok'], implode(' | ', array_filter($r, fn($x) => $x !== 'ok')));
}
$s = new Talixio\Store("$d/old-1.db");
check('migrate: an old database keeps its rows and gets the new columns', $s->q('SELECT content, space FROM messages')->fetch() === ['content' => 'hello', 'space' => 0]);
$v = (int)$s->q('PRAGMA user_version')->fetchColumn();
check('migrate: the schema version is recorded', $v !== 0, (string)$v);
$t = microtime(true); for ($i = 0; $i < 20; $i++) new Talixio\Store("$d/old-1.db"); $warm = (microtime(true) - $t) / 20;
check('migrate: opening an up-to-date database does not migrate again (fast)', $warm < 0.01, sprintf('%.4f s per open', $warm));
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
