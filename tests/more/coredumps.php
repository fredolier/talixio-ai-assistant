<?php
declare(strict_types=1);
// Crash dumps (core.<pid>) left in the data folder: the doctor says which program crashed, cleanup deletes them (1.35.47).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, CoreDumps, Doctor};

/** A minimal ELF64 core file with an NT_PRPSINFO note, as Linux writes it. */
function fake_core(string $fname, string $psargs, int $pad = 0): string
{
    $desc = str_repeat("\0", 40) . str_pad($fname, 16, "\0") . str_pad($psargs, 80, "\0");
    $note = pack('V3', 5, strlen($desc), 3) . "CORE\0\0\0\0" . $desc;
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

putenv($keep === false ? 'TALIXIO_DATA_DIR' : "TALIXIO_DATA_DIR=$keep");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
