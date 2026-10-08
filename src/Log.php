<?php
declare(strict_types=1);
namespace Talixio;

/** Logs to stderr: journald under systemd. php://stderr works in every SAPI (the STDERR constant doesn't exist in the web server). */
final class Log
{
    public static function info(string $m): void { self::write("INFO $m"); }
    public static function warn(string $m): void { self::write("WARN $m"); }
    public static function error(string $m): void { self::write("ERROR $m"); }

    private static function write(string $line): void
    {
        @file_put_contents('php://stderr', $line . "\n");
    }
}
