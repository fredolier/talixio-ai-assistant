<?php
declare(strict_types=1);
namespace Talixio;

/** iCalendar (RFC 5545) events: parsing and expansion of common recurrences within a time range. */
final class ICal
{
    /** @return array<array{uid:string,title:string,start:int,end:int,all_day:bool,location:string,description:string,calendar:string}> */
    public static function events(string $ics, int $from, int $to, string $calendar = ''): array
    {
        $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)); // unfold
        preg_match_all('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $ics, $blocks);
        $base = []; $overrides = [];
        foreach ($blocks[1] as $b) {
            $p = self::props($b);
            if (!isset($p['DTSTART'])) continue;
            if (strtoupper($p['STATUS'][0]['v'] ?? '') === 'CANCELLED') { if (isset($p['RECURRENCE-ID'])) $overrides[$p['UID'][0]['v'] ?? ''][self::time($p['RECURRENCE-ID'][0])[0]] = null; continue; }
            if (isset($p['RECURRENCE-ID'])) { $overrides[$p['UID'][0]['v'] ?? ''][self::time($p['RECURRENCE-ID'][0])[0]] = $p; continue; }
            $base[] = $p;
        }
        $out = [];
        foreach ($base as $p) {
            $uid = $p['UID'][0]['v'] ?? md5(json_encode($p));
            [$start, $allDay, $tz] = self::time($p['DTSTART'][0]);
            $dur = isset($p['DTEND']) ? self::time($p['DTEND'][0])[0] - $start : (isset($p['DURATION']) ? self::duration($p['DURATION'][0]['v']) : ($allDay ? 86400 : 0));
            $ex = [];
            foreach ($p['EXDATE'] ?? [] as $e) foreach (explode(',', $e['v']) as $v) $ex[self::time(['v' => $v, 'params' => $e['params']])[0]] = true;
            $starts = isset($p['RRULE']) ? self::expand($p['RRULE'][0]['v'], $start, $tz, $allDay, $from - max($dur, 0), $to) : [$start];
            foreach ($starts as $s) {
                if (isset($ex[$s])) continue;
                $ov = $overrides[$uid][$s] ?? false;
                if ($ov === null) continue; // cancelled occurrence
                $q = $ov ?: $p;
                $st = $ov ? self::time($ov['DTSTART'][0])[0] : $s;
                $en = $ov && isset($ov['DTEND']) ? self::time($ov['DTEND'][0])[0] : $st + $dur;
                if ($en <= $from && !($en === $st && $st >= $from) || $st >= $to) continue;
                $out[] = ['uid' => $uid, 'title' => self::text($q['SUMMARY'][0]['v'] ?? '(no title)'), 'start' => $st, 'end' => $en, 'all_day' => $allDay,
                    'location' => self::text($q['LOCATION'][0]['v'] ?? ''), 'description' => mb_substr(self::text($q['DESCRIPTION'][0]['v'] ?? ''), 0, 500), 'calendar' => $calendar];
            }
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    private static function props(string $block): array
    {
        $p = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
            $params = [];
            foreach (array_filter(explode(';', $m[2])) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $params[strtoupper($k)] = trim($v, '"'); }
            $p[$m[1]][] = ['v' => $m[3], 'params' => $params];
        }
        return $p;
    }

    public static function text(string $v): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    }

    /** @return array{0:int,1:bool,2:\DateTimeZone} timestamp, all-day, zone */
    private static function time(array $prop): array
    {
        $v = trim($prop['v']);
        $local = new \DateTimeZone(date_default_timezone_get());
        if (($prop['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $v)) {
            return [(new \DateTimeImmutable(substr($v, 0, 8), $local))->getTimestamp(), true, $local];
        }
        if (str_ends_with($v, 'Z')) return [(new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->getTimestamp(), false, new \DateTimeZone('UTC')];
        $tz = $local;
        if (isset($prop['params']['TZID'])) {
            $id = self::WINDOWS_ZONES[$prop['params']['TZID']] ?? $prop['params']['TZID'];
            try { $tz = new \DateTimeZone(preg_replace('#^/#', '', $id)); } catch (\Exception) {}
        }
        return [(new \DateTimeImmutable($v, $tz))->getTimestamp(), false, $tz];
    }

    private static function duration(string $d): int
    {
        if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $d, $m)) return 0;
        $s = ((int)($m[2] ?? 0)) * 604800 + ((int)($m[3] ?? 0)) * 86400 + ((int)($m[4] ?? 0)) * 3600 + ((int)($m[5] ?? 0)) * 60 + (int)($m[6] ?? 0);
        return ($m[1] ?? '') === '-' ? -$s : $s;
    }

    /** Occurrence start times of an RRULE between $from and $to (DAILY/WEEKLY/MONTHLY/YEARLY, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY). */
    private static function expand(string $rule, int $start, \DateTimeZone $tz, bool $allDay, int $from, int $to): array
    {
        $r = [];
        foreach (explode(';', $rule) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $r[strtoupper($k)] = $v; }
        $freq = $r['FREQ'] ?? 'DAILY'; $interval = max(1, (int)($r['INTERVAL'] ?? 1));
        $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
        $until = isset($r['UNTIL']) ? self::time(['v' => $r['UNTIL'], 'params' => []])[0] + ($allDay ? 86399 : 0) : null;
        $dtStart = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
        $days = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $byday = [];
        foreach (array_filter(explode(',', $r['BYDAY'] ?? '')) as $d) if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m)) $byday[] = [(int)($m[1] ?? 0), $days[$m[2]]];
        $bymd = array_map('intval', array_filter(explode(',', $r['BYMONTHDAY'] ?? '')));
        $out = []; $n = 0;
        $time = [(int)$dtStart->format('G'), (int)$dtStart->format('i'), (int)$dtStart->format('s')];
        for ($period = 0; $period < 5000; $period++) {
            $cands = [];
            switch ($freq) {
                case 'DAILY': $cands[] = $dtStart->modify('+' . ($period * $interval) . ' days'); break;
                case 'WEEKLY':
                    $weekStart = $dtStart->modify('-' . (((int)$dtStart->format('N')) - 1) . ' days')->modify('+' . ($period * $interval) . ' weeks');
                    foreach ($byday ?: [[0, (int)$dtStart->format('w')]] as [, $wd]) $cands[] = $weekStart->modify('+' . (($wd + 6) % 7) . ' days')->setTime(...$time);
                    break;
                case 'MONTHLY':
                    $month = $dtStart->modify('first day of this month')->modify('+' . ($period * $interval) . ' months')->setTime(...$time);
                    if ($byday) {
                        foreach ($byday as [$nth, $wd]) {
                            $all = [];
                            for ($d = $month; $d->format('m') === $month->format('m'); $d = $d->modify('+1 day')) if ((int)$d->format('w') === $wd) $all[] = $d;
                            if ($nth === 0) array_push($cands, ...$all); elseif (isset($all[$nth > 0 ? $nth - 1 : count($all) + $nth])) $cands[] = $all[$nth > 0 ? $nth - 1 : count($all) + $nth];
                        }
                    } else {
                        foreach ($bymd ?: [(int)$dtStart->format('j')] as $md) {
                            $dim = (int)$month->format('t'); $day = $md < 0 ? $dim + $md + 1 : $md;
                            if ($day >= 1 && $day <= $dim) $cands[] = $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $day);
                        }
                    }
                    break;
                case 'YEARLY': $cands[] = $dtStart->modify('+' . ($period * $interval) . ' years'); break;
                default: return [$start];
            }
            usort($cands, fn($a, $b) => $a <=> $b);
            foreach ($cands as $c) {
                $t = $c->getTimestamp();
                if ($t < $start) continue;
                if (($until !== null && $t > $until) || ($count !== null && $n >= $count) || $t >= $to) return $out;
                $n++;
                if ($t >= $from) $out[] = $t;
            }
        }
        return $out;
    }

    /** Outlook/Exchange calendars use Windows zone names. */
    private const WINDOWS_ZONES = [
        'Romance Standard Time' => 'Europe/Paris', 'W. Europe Standard Time' => 'Europe/Berlin', 'GMT Standard Time' => 'Europe/London',
        'Central Europe Standard Time' => 'Europe/Budapest', 'Pacific Standard Time' => 'America/Los_Angeles', 'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago', 'UTC' => 'UTC', 'Greenwich Standard Time' => 'Atlantic/Reykjavik',
    ];
}
