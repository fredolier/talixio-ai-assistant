<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Commands in a sandbox (bubblewrap): the assistant gets a Linux machine of its own to compute, convert files,
 * analyse data with Python... It sees only the system programs (read-only) and its work folder (/work, kept
 * between runs); not the rest of the server, its data, keys or other processes. No network unless allowed.
 * TALIXIO_SHELL: off | ask (every command waits for your approval) | auto (runs without asking, still sandboxed).
 */
final class Shell
{
    public const MAX_OUTPUT = 30000;

    public function __construct(private App $app) {}

    public static function binary(): ?string
    {
        foreach (['/usr/bin/bwrap', '/usr/local/bin/bwrap'] as $b) if (is_executable($b)) return $b;
        return null;
    }

    public function enabled(): bool { return $this->app->cfg->shell !== 'off' && self::binary() !== null; }

    public function workDir(): string
    {
        $d = $this->app->cfg->dataDir . '/sandbox/work';
        if (!is_dir($d)) mkdir($d, 0700, true);
        return $d;
    }

    /** The bubblewrap command line for one shell command. */
    public function argv(string $command, bool $network): array
    {
        $a = [self::binary(), '--die-with-parent', '--new-session', '--unshare-all'];
        if ($network) $a[] = '--share-net';
        array_push($a, '--ro-bind', '/usr', '/usr', '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
            '--bind', $this->workDir(), '/work', '--chdir', '/work', '--clearenv',
            '--setenv', 'HOME', '/work', '--setenv', 'PATH', '/work/.local/bin:/usr/local/bin:/usr/bin:/bin', '--setenv', 'LANG', 'C.UTF-8',
            '--setenv', 'TZ', $this->app->cfg->timezone, '--setenv', 'MPLBACKEND', 'Agg', '--setenv', 'PYTHONUNBUFFERED', '1');
        foreach (['bin', 'lib', 'lib64', 'sbin'] as $l) if (is_link("/$l")) array_push($a, '--symlink', "usr/$l", "/$l");
        // what programs commonly need from /etc (certificates for https, time zones, fonts for charts); nothing secret
        foreach (['/etc/alternatives', '/etc/ssl', '/etc/ca-certificates', '/etc/fonts', '/etc/localtime', '/etc/mime.types', '/etc/python3', '/etc/matplotlibrc'] as $e) array_push($a, '--ro-bind-try', $e, $e);
        if ($network) foreach (['/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf', '/etc/gai.conf'] as $e) array_push($a, '--ro-bind-try', $e, $e);
        // memory 2 GB, files up to 1 GB, at most 256 processes
        array_push($a, '/bin/sh', '-c', 'ulimit -v 2097152 2>/dev/null; ulimit -f 2097152 2>/dev/null; ulimit -u 256 2>/dev/null; exec /bin/sh -c "$1"', 'sandbox', $command);
        return $a;
    }

    /**
     * Run a command; returns its output (stdout and stderr together, shortened) and exit code.
     * $files: library file ids copied into /work/in first.
     */
    public function run(string $command, array $files = [], ?int $timeout = null): string
    {
        if (!$this->enabled()) throw new \RuntimeException('the sandboxed shell is off (TALIXIO_SHELL) or bubblewrap is not installed (install.sh --with-shell)');
        $timeout ??= $this->app->cfg->shellTimeout;
        $copied = [];
        foreach ($files as $id) {
            $f = $this->app->files()->get((int)$id) ?? throw new \InvalidArgumentException("no file #$id");
            if (!is_dir($this->workDir() . '/in')) mkdir($this->workDir() . '/in', 0700);
            $name = preg_replace('/[^\w.() -]+/u', '_', $f['name']);
            copy($f['path'], $this->workDir() . "/in/$name");
            $copied[] = "in/$name";
        }
        $proc = proc_open($this->argv($command, $this->app->cfg->shellNetwork), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($proc)) throw new \RuntimeException('could not start the sandbox');
        stream_set_blocking($pipes[1], false);
        $out = ''; $start = microtime(true); $timedOut = false; $cut = false;
        while (true) {
            $r = [$pipes[1]]; $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 200000)) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === '' || $chunk === false) { if (feof($pipes[1])) break; }
                elseif (strlen($out) < self::MAX_OUTPUT * 4) $out .= $chunk; else $cut = true;
            }
            if (!proc_get_status($proc)['running'] && feof($pipes[1])) break;
            if (microtime(true) - $start > $timeout) { $timedOut = true; break; }
        }
        if ($timedOut) { proc_terminate($proc, 9); }
        fclose($pipes[1]);
        $status = proc_get_status($proc);
        $code = proc_close($proc);
        if ($code === -1 && isset($status['exitcode'])) $code = $status['exitcode'];
        if (!mb_check_encoding($out, 'UTF-8')) $out = mb_convert_encoding($out, 'UTF-8', 'UTF-8');
        if (mb_strlen($out) > self::MAX_OUTPUT) { $out = mb_substr($out, 0, (int)(self::MAX_OUTPUT * 0.3)) . "\n[… output shortened …]\n" . mb_substr($out, -(int)(self::MAX_OUTPUT * 0.6)); }
        elseif ($cut) $out .= "\n[… output shortened …]";
        return ($copied ? 'Copied into /work: ' . implode(', ', $copied) . "\n" : '')
            . ($timedOut ? "[stopped after {$timeout} s]\n" : "[exit code $code]\n") . ($out === '' ? '(no output)' : $out);
    }

    /** Save a file from /work into the library. */
    public function save(string $path, string $folder = '', string $name = ''): array
    {
        $work = realpath($this->workDir());
        $rel = ltrim(preg_replace('#^/?work(/|$)#', '', $path), '/');
        $real = realpath($this->workDir() . '/' . $rel);
        if (!$real || !str_starts_with($real, "$work/") || !is_file($real)) throw new \InvalidArgumentException("no file $path in /work");
        $tmp = tempnam(sys_get_temp_dir(), 'shell');
        copy($real, $tmp);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';
        $id = $this->app->files()->save($tmp, $name !== '' ? $name : basename($real), $mime, $folder !== '' ? $folder : (($sf = $this->app->space ? (string)($this->app->spaces()->get($this->app->space)['folder'] ?? '') : '') !== '' ? $sf : Library::INBOX));
        return $this->app->files()->get($id);
    }

    /** Can the sandbox start here (user namespaces allowed)? */
    public function check(): string
    {
        if (self::binary() === null) throw new \RuntimeException('bubblewrap is not installed (install.sh --with-shell)');
        $out = $this->run('echo sandbox-ok; python3 --version 2>&1 || true', [], 20);
        if (!str_contains($out, 'sandbox-ok')) throw new \RuntimeException('bubblewrap cannot start: ' . mb_substr($out, 0, 300));
        return trim(preg_replace('/^\[exit code 0\]\n/', '', $out));
    }
}
