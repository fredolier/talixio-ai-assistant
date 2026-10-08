<?php
declare(strict_types=1);
namespace Talixio;

final class Log // stderr => journald under systemd
{
    public static function info(string $m): void { fwrite(STDERR, "INFO $m\n"); }
    public static function warn(string $m): void { fwrite(STDERR, "WARN $m\n"); }
    public static function error(string $m): void { fwrite(STDERR, "ERROR $m\n"); }
}
