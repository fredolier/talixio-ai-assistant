<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Crash dumps ("core.<pid>") a crashed program leaves in the folder it ran in: the data folder, in the multi-user
 * server's containers. They take room and went into the host's backups. The doctor lists them with the program that
 * crashed, read from the dump itself (no `file` or `strings` needed), and `talixio cleanup` deletes them.
 */
final class CoreDumps
{
    /** @return list<array{file:string,size:int,time:int,program:string,args:string,signal:int}> newest first */
    public static function find(string $dir): array
    {
        $out = [];
        foreach (glob(rtrim($dir, '/') . '/core.*') ?: [] as $f) {
            if (!preg_match('/^core\.\d+$/', basename($f)) || !is_file($f) || is_link($f)) continue;
            $p = self::program($f);
            $out[] = ['file' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f), 'program' => $p['program'] ?? '', 'args' => $p['args'] ?? '',
                'signal' => self::signal($f)];
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    /**
     * The crashed program's name and command line, from the dump's NT_PRPSINFO note (ELF 64-bit, little-endian:
     * x86-64 and arm64). Null when the file is not such a dump.
     * @return array{program:string,args:string}|null
     */
    public static function program(string $path): ?array
    {
        $d = self::note($path, 3, 136); // NT_PRPSINFO: 40 bytes of state and ids, then pr_fname[16], pr_psargs[80]
        if ($d === null) return null;
        $z = fn(string $s) => trim(explode("\0", $s, 2)[0]);
        return ['program' => $z(substr($d, 40, 16)), 'args' => $z(substr($d, 56, 80))];
    }

    /** The signal that ended the program (11 = SIGSEGV…), from the dump's first NT_PRSTATUS note; 0 when unknown. */
    public static function signal(string $path): int
    {
        $d = self::note($path, 1, 14); // NT_PRSTATUS: si_signo, si_code, si_errno (3 ints), then pr_cursig (short)
        if ($d === null) return 0;
        return unpack('v', substr($d, 12, 2))[1] ?: unpack('V', substr($d, 0, 4))[1];
    }

    /** What a signal number means, for people. */
    public static function signalName(int $sig): string
    {
        return match ($sig) {
            0 => '',
            4 => 'SIGILL, illegal instruction',
            5 => 'SIGTRAP, failed internal check',
            6 => 'SIGABRT, aborted',
            7 => 'SIGBUS, memory error (often shared memory full)',
            8 => 'SIGFPE, arithmetic error',
            11 => 'SIGSEGV, memory fault',
            31 => 'SIGSYS, system call refused by a sandbox',
            default => "signal $sig",
        };
    }

    /** The data of the first note of $type (at least $min bytes) in an ELF 64-bit little-endian core file (x86-64, arm64). */
    private static function note(string $path, int $type, int $min): ?string
    {
        $h = @fopen($path, 'rb');
        if (!$h) return null;
        try {
            $e = fread($h, 64);
            if (strlen($e) < 64 || substr($e, 0, 4) !== "\x7fELF" || ord($e[4]) !== 2 || ord($e[5]) !== 1) return null;
            if (unpack('v', substr($e, 16, 2))[1] !== 4) return null; // ET_CORE
            $phoff = unpack('P', substr($e, 32, 8))[1]; $phsize = unpack('v', substr($e, 54, 2))[1]; $phnum = unpack('v', substr($e, 56, 2))[1];
            if ($phsize < 56 || $phnum < 1 || $phnum > 4096) return null;
            for ($i = 0; $i < $phnum; $i++) {
                fseek($h, $phoff + $i * $phsize);
                $ph = fread($h, 56);
                if (strlen($ph) < 56 || unpack('V', substr($ph, 0, 4))[1] !== 4) continue; // PT_NOTE
                $off = unpack('P', substr($ph, 8, 8))[1]; $len = min(unpack('P', substr($ph, 32, 8))[1], 1 << 20);
                fseek($h, $off);
                $notes = (string)fread($h, (int)$len);
                for ($p = 0; $p + 12 <= strlen($notes);) {
                    [, $nsz, $dsz, $t] = unpack('V3', substr($notes, $p, 12));
                    $d = $p + 12 + (($nsz + 3) & ~3);
                    if ($t === $type && $dsz >= $min && $d + $dsz <= strlen($notes)) return substr($notes, $d, $dsz);
                    $p = $d + (($dsz + 3) & ~3);
                }
            }
            return null;
        } finally { fclose($h); }
    }

    /** One line for the doctor, or null when there is none. */
    public static function summary(array $dumps): ?string
    {
        if (!$dumps) return null;
        $mb = (int)ceil(array_sum(array_column($dumps, 'size')) / 1048576);
        $by = array_count_values(array_map(fn($d) => $d['program'] !== '' ? $d['program'] : 'unknown program', $dumps));
        arsort($by);
        $who = implode(', ', array_map(fn($p, $n) => "$p ×$n", array_keys($by), $by));
        $cmd = $dumps[0]['args'] !== '' ? " (last: \"{$dumps[0]['args']}\")" : '';
        if ($sig = self::signalName($dumps[0]['signal'] ?? 0)) $cmd .= ", ended by $sig";
        return count($dumps) . " crash dump(s) in the data folder, $mb MB: $who; latest " . date('Y-m-d H:i', $dumps[0]['time']) . "$cmd. Delete them with \"talixio cleanup\" (or the button).";
    }

    /** Delete them. @return array{0:int,1:int} how many, bytes freed */
    public static function clean(string $dir): array
    {
        $n = 0; $bytes = 0;
        foreach (self::find($dir) as $d) if (@unlink(rtrim($dir, '/') . '/' . $d['file'])) { $n++; $bytes += $d['size']; }
        return [$n, $bytes];
    }
}
