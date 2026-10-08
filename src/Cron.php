<?php
declare(strict_types=1);
namespace Talixio;

/** Standard 5-field cron (minute hour day-of-month month day-of-week), evaluated in local time. */
final class Cron
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @return array<int,array<int,bool>> */
    public static function parse(string $expr): array
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) throw new \InvalidArgumentException("cron needs 5 fields: $expr");
        $sets = [];
        foreach ($parts as $i => $part) {
            [$lo, $hi] = self::RANGES[$i];
            $set = [];
            foreach (explode(',', $part) as $item) {
                if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $item, $m)) throw new \InvalidArgumentException("bad cron field: $item");
                $step = (int)($m[2] ?? 1) ?: 1;
                if ($m[1] === '*') {
                    [$a, $b] = [$lo, $hi];
                } elseif (str_contains($m[1], '-')) {
                    [$a, $b] = array_map('intval', explode('-', $m[1]));
                } else {
                    $a = (int)$m[1];
                    $b = isset($m[2]) ? $hi : $a; // "5/15" means from 5 to the end, every 15
                }
                if ($a < $lo || $b > $hi || $a > $b) throw new \InvalidArgumentException("cron value out of range: $item");
                for ($v = $a; $v <= $b; $v += $step) $set[$v] = true;
            }
            $sets[] = $set;
        }
        if (isset($sets[4][7])) $sets[4][0] = true; // 7 == Sunday
        return $sets;
    }

    /** Next matching unix time strictly after $after. */
    public static function next(string $expr, int $after): int
    {
        [$min, $hour, $dom, $mon, $dow] = self::parse($expr);
        $domAny = count($dom) === 31; $dowAny = count($dow) >= 7;
        $t = (new \DateTimeImmutable('@' . ($after - $after % 60 + 60)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        for ($guard = 0; $guard < 100000; $guard++) {
            if (!isset($mon[(int)$t->format('n')])) { $t = $t->modify('first day of next month')->setTime(0, 0); continue; }
            $d = isset($dom[(int)$t->format('j')]); $w = isset($dow[(int)$t->format('w')]);
            // cron rule: if both day fields are restricted, either may match
            $dayOk = $domAny && $dowAny ? true : ($domAny ? $w : ($dowAny ? $d : ($d || $w)));
            if (!$dayOk) { $t = $t->modify('+1 day')->setTime(0, 0); continue; }
            if (!isset($hour[(int)$t->format('G')])) { $t = $t->setTime((int)$t->format('G'), 0)->modify('+1 hour'); continue; }
            if (!isset($min[(int)$t->format('i')])) { $t = $t->modify('+1 minute'); continue; }
            return $t->getTimestamp();
        }
        throw new \RuntimeException("no next run for cron $expr");
    }
}
