<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Standard 5-field cron (minute hour day-of-month month day-of-week), evaluated in local time.
 * Also "D#N" in the day-of-week field: the N-th such weekday of the month ("1#1" = the first Monday). Needed because
 * "1-7 * 1" means "days 1 to 7 OR every Monday" in cron (either restricted day field may match), not "first Monday".
 */
final class Cron
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @return array<int,array<int,bool>> */
    public static function parse(string $expr): array
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) throw new \InvalidArgumentException("cron needs 5 fields: $expr");
        $sets = []; $nth = [];
        foreach ($parts as $i => $part) {
            [$lo, $hi] = self::RANGES[$i];
            $set = [];
            foreach (explode(',', $part) as $item) {
                if ($i === 4 && preg_match('/^([0-7])#([1-5])$/', $item, $m)) { $nth[(int)$m[1] % 7][(int)$m[2]] = true; continue; }
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
        $sets[] = $nth; // [weekday => [n => true]]
        return $sets;
    }

    /** Next matching unix time strictly after $after. */
    public static function next(string $expr, int $after): int
    {
        [$min, $hour, $dom, $mon, $dow, $nth] = self::parse($expr);
        $domAny = count($dom) === 31; $dowAny = count($dow) >= 7 && !$nth;
        $t = (new \DateTimeImmutable('@' . ($after - $after % 60 + 60)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        for ($guard = 0; $guard < 100000; $guard++) {
            if (!isset($mon[(int)$t->format('n')])) { $t = $t->modify('first day of next month')->setTime(0, 0); continue; }
            $d = isset($dom[(int)$t->format('j')]);
            $w = isset($dow[(int)$t->format('w')]) || isset($nth[(int)$t->format('w')][intdiv((int)$t->format('j') - 1, 7) + 1]);
            // cron rule: if both day fields are restricted, either may match
            $dayOk = $domAny && $dowAny ? true : ($domAny ? $w : ($dowAny ? $d : ($d || $w)));
            if (!$dayOk) { $t = $t->modify('+1 day')->setTime(0, 0); continue; }
            if (!isset($hour[$g = (int)$t->format('G')])) {
                $n = $t->setTime($g, 0)->modify('+1 hour'); $ng = (int)$n->format('G');
                // the clock jumps forward (summer time): a run planned in the skipped hour happens right after it, not a day later (audit Q16)
                if ($ng > $g + 1 && $n->format('Ymd') === $t->format('Ymd')) for ($h = $g + 1; $h < $ng; $h++) {
                    if (isset($hour[$h])) { $c = $n->setTime($ng, (int)min(array_keys($min))); if ($c->getTimestamp() > $after) return $c->getTimestamp(); }
                }
                $t = $n; continue;
            }
            if (!isset($min[(int)$t->format('i')])) { $t = $t->modify('+1 minute'); continue; }
            return $t->getTimestamp();
        }
        throw new \RuntimeException("no next run for cron $expr");
    }
}
