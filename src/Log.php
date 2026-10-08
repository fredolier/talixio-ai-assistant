<?php
declare(strict_types=1);
namespace Talixio;

final class Log // goes to stderr => journald under systemd
{
    public static function warn(string $m): void { self::w('WARN', $m); }
    public static function error(string $m): void { self::w('ERROR', $m); }
    public static function info(string $m): void { self::w('INFO', $m); }
    private static function w(string $l, string $m): void { fwrite(STDERR, "$l $m\n"); }
}
