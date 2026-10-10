<?php
declare(strict_types=1);
namespace Talixio;

/** A process that goes on after the web request that started it, in its own session, holding none of the web server's files (its listening socket). */
final class Background
{
    public static function start(array $cmd, string $log): void
    {
        $sh = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; exec setsid -f '
            . implode(' ', array_map('escapeshellarg', $cmd)) . ' </dev/null >>' . escapeshellarg($log) . ' 2>&1';
        $p = proc_open(['bash', '-c', $sh], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) throw new \RuntimeException('could not start ' . basename((string)$cmd[0]));
        proc_close($p); // setsid -f: bash returns at once
    }
}
