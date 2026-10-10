<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Which version is running: the number in the VERSION file (raised with each delivery, see CHANGELOG.md), and the
 * exact commit and its date, written by install.sh (or the Docker build) in VERSION.json, or read from .git in a clone.
 */
final class Version
{
    public static function get(): array
    {
        $root = dirname(__DIR__);
        $number = trim((string)@file_get_contents("$root/VERSION")) ?: '0.0.0';
        $v = json_decode((string)@file_get_contents("$root/VERSION.json"), true) ?: [];
        if (!$v && is_file("$root/.git/HEAD")) $v = ['sha' => self::gitHead("$root/.git"), 'branch' => 'clone'];
        $sha = (string)($v['sha'] ?? '');
        $short = (string)($v['short'] ?? substr($sha, 0, 7));
        $date = substr((string)($v['date'] ?? ''), 0, 10);
        return $v + ['number' => $number, 'sha' => $sha, 'short' => $short, 'date' => $date,
            'label' => $number . ($short !== '' ? " ($short" . ($date !== '' ? ", $date" : '') . ')' : '')];
    }

    /** The commit a clone is on, without running git. */
    private static function gitHead(string $git): string
    {
        $head = trim((string)@file_get_contents("$git/HEAD"));
        if (!str_starts_with($head, 'ref: ')) return $head;
        $ref = substr($head, 5);
        if (is_file("$git/$ref")) return trim((string)file_get_contents("$git/$ref"));
        foreach (file("$git/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $l) if (str_ends_with($l, " $ref")) return substr($l, 0, 40);
        return '';
    }
}
