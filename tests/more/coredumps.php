<?php
declare(strict_types=1);
// Crash dumps (core.<pid>) left in the data folder: the doctor says which program crashed, cleanup deletes them (1.35.47).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Browser, CoreDumps, Doctor};

/** A minimal ELF64 core file with an NT_PRPSINFO note, as Linux writes it. */
function fake_core(string $fname, string $psargs, int $pad = 0, int $sig = 0): string
{
    $desc = str_repeat("\0", 40) . str_pad($fname, 16, "\0") . str_pad($psargs, 80, "\0");
    $note = pack('V3', 5, strlen($desc), 3) . "CORE\0\0\0\0" . $desc;
    if ($sig) { $st = pack('V3v', $sig, 0, 0, $sig) . str_repeat("\0", 322); $note = pack('V3', 5, strlen($st), 1) . "CORE\0\0\0\0" . $st . $note; } // NT_PRSTATUS first, as Linux does
    $ehdr = "\x7fELF" . chr(2) . chr(1) . chr(1) . str_repeat("\0", 9) . pack('vvV', 4, 62, 1) . pack('PPP', 0, 64, 0) . pack('Vvvvvvv', 0, 64, 56, 1, 0, 0, 0);
    $phdr = pack('VV', 4, 0) . pack('PPPPPP', 120, 0, 0, strlen($note), 0, 4);
    return $ehdr . $phdr . $note . str_repeat("\0", $pad);
}

$d = test_dir('coredumps');
$keep = getenv('TALIXIO_DATA_DIR'); putenv("TALIXIO_DATA_DIR=$d");
file_put_contents("$d/core.17268", fake_core('php', 'php /opt/talixio/bin/talixio worker', 3 << 20)); touch("$d/core.17268", strtotime('2026-10-29 17:58'));
file_put_contents("$d/core.24238", fake_core('soffice.bin', '/usr/lib/libreoffice/program/soffice.bin --headless')); touch("$d/core.24238", strtotime('2026-10-29 18:20'));
file_put_contents("$d/core.2", fake_core('php', 'php -S 0.0.0.0:8080')); touch("$d/core.2", strtotime('2026-10-18 14:50'));
file_put_contents("$d/core.notes.md", 'my notes about core.*'); // not a dump: kept
file_put_contents("$d/core.9", 'not an ELF file');              // a dump we cannot read: listed, program unknown

check('crash dumps: the program and its command line are read from the dump', CoreDumps::program("$d/core.24238") === ['program' => 'soffice.bin', 'args' => '/usr/lib/libreoffice/program/soffice.bin --headless']
    && CoreDumps::program("$d/core.9") === null && CoreDumps::program("$d/core.notes.md") === null);
$f = CoreDumps::find($d);
check('crash dumps: only core.<number> files, newest first', array_column($f, 'file') === ['core.9', 'core.24238', 'core.17268', 'core.2'], json_encode(array_column($f, 'file')));
$app = App::fromEnv();
$line = array_values(array_filter((new Doctor($app))->run(false), fn($r) => str_contains($r['text'], 'crash dump')))[0] ?? [];
check('crash dumps: the doctor lists them with the programs, the size and the latest', ($line['status'] ?? '') === 'fail' && ($line['action'] ?? '') === 'clean-dumps'
    && str_contains($line['text'], '4 crash dump(s)') && str_contains($line['text'], 'php ×2') && str_contains($line['text'], 'soffice.bin ×1') && str_contains($line['text'], 'unknown program ×1')
    && str_contains($line['text'], 'MB') && str_contains($line['text'], 'talixio cleanup'), json_encode($line));
[$n, $bytes] = CoreDumps::clean($d);
check('crash dumps: cleanup deletes them, and nothing else', $n === 4 && $bytes > 3 << 20 && !glob("$d/core.[0-9]*") && is_file("$d/core.notes.md"), "$n $bytes");
check('crash dumps: none left, no doctor line', !array_filter((new Doctor($app))->run(false), fn($r) => str_contains($r['text'], 'crash dump')));

// the command
file_put_contents("$d/core.77", fake_core('whisper-cli', 'whisper-cli -m model.bin'));
$out = shell_exec('TALIXIO_DATA_DIR=' . escapeshellarg($d) . ' TALIXIO_PROVIDER=echo ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/talixio') . ' cleanup 2>&1');
check('crash dumps: "talixio cleanup" lists what it deletes', str_contains((string)$out, 'core.77') && str_contains((string)$out, 'whisper-cli') && str_contains((string)$out, '1 crash dump(s) deleted') && !is_file("$d/core.77"), (string)$out);

// 1.35.50: the signal that ended the program, and Chromium no longer leaving dumps
file_put_contents("$d/core.1", fake_core('chromium', '/usr/lib/chromium/chromium --type=zygote --headless=new', 0, 5));
file_put_contents("$d/core.2", fake_core('php', 'php worker'));
check('crash dumps: the signal is read from the dump', CoreDumps::signal("$d/core.1") === 5 && CoreDumps::signal("$d/core.2") === 0 && CoreDumps::signal("$d/core.notes.md") === 0
    && CoreDumps::program("$d/core.1")['program'] === 'chromium');
touch("$d/core.1", time() + 5);
$sum = (string)CoreDumps::summary(CoreDumps::find($d));
check('crash dumps: the doctor says what ended the latest one', str_contains($sum, 'ended by SIGTRAP, failed internal check') && str_contains($sum, 'chromium ×1'), $sum);
$out = shell_exec('TALIXIO_DATA_DIR=' . escapeshellarg($d) . ' TALIXIO_PROVIDER=echo ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/talixio') . ' cleanup 2>&1');
check('crash dumps: "talixio cleanup" shows the signal too', str_contains((string)$out, 'SIGTRAP'), (string)$out);

// a real dump: made by the kernel here, when this system writes them as files
$real = test_dir('coredumps-real');
$crash = fn(array $cmd) => proc_close(proc_open(['bash', '-c', 'ulimit -c unlimited 2>/dev/null; "$@"', 'x', ...$cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, $real));
$crash(['sh', '-c', 'kill -SEGV $$']);
$made = array_values(array_filter(glob("$real/core*") ?: [], 'is_file'));
if ($made) {
    check('crash dumps: a real dump gives its program and signal 11', (CoreDumps::program($made[0])['program'] ?? '') === 'sh' && CoreDumps::signal($made[0]) === 11);
    array_map('unlink', $made);
    $crash(Browser::command(['sh', '-c', 'kill -SEGV $$']));
    check('browser: Chromium is started with core dumps off (the same crash leaves no dump)', !array_filter(glob("$real/core*") ?: [], 'is_file'));
} else echo "skip crash dumps: this system does not write core files to the folder\n";
check('browser: the start command keeps the arguments as they are', array_slice(Browser::command(['/usr/bin/chromium', '--headless=new', 'a b']), -3) === ['/usr/bin/chromium', '--headless=new', 'a b']
    && trim((string)shell_exec(implode(' ', array_map('escapeshellarg', Browser::command(['sh', '-c', 'ulimit -c; echo "$1"', 'x', 'a b']))))) === "0\na b");

// closing: Chromium gets time to finish before it is killed (it got 0.2 s, and its page processes crashed)
$flag = "$real/closed";
$p = proc_open(['sh', '-c', 'sleep 0.6; echo done > "$0"', $flag], [], $pp);
$t = microtime(true); Browser::stop($p);
check('browser: closing waits for Chromium to finish', trim((string)@file_get_contents($flag)) === 'done' && microtime(true) - $t < 1.8);
$p = proc_open(['sleep', '30'], [], $pp); $pid = proc_get_status($p)['pid'];
$t = microtime(true); Browser::stop($p, 0.5); $took = microtime(true) - $t;
check('browser: one that does not close is killed after the wait', $took >= 0.45 && $took < 3 && !file_exists("/proc/$pid/stat"), (string)$took);

putenv($keep === false ? 'TALIXIO_DATA_DIR' : "TALIXIO_DATA_DIR=$keep");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
