<?php
declare(strict_types=1);
namespace Talixio;

/**
 * A schedule said in plain words (English or French) turned into a cron expression or a date: "every weekday at 8:00",
 * "tous les lundis à 9h30", "le 1er de chaque mois à 9h", "tomorrow at 14:30", "dans 2 heures". Small models get cron
 * fields, weekday numbers and time zones wrong; they write the words well. A cron expression or "YYYY-MM-DD HH:MM" also works.
 */
final class When
{
    public const EXAMPLES = "'every weekday at 8:00', 'every monday and thursday at 18:30', 'every day at 7am', 'every 2 hours', "
        . "'on the 1st of every month at 9:00', 'tomorrow at 14:30', 'in 20 minutes', '2026-11-03 09:00', "
        . "or in French: 'tous les jours à 7h', 'du lundi au vendredi à 8h', 'chaque mardi à 18h30', 'demain à 9h'";

    private const DAYS = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6,
        'dimanche' => 0, 'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6,
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'tues' => 2, 'wed' => 3, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'fri' => 5, 'sat' => 6];
    private const MONTHS = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12, 'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];

    /**
     * @return array{cron:?string, at:?int} exactly one is set
     * @throws \InvalidArgumentException with examples of what works
     */
    public static function parse(string $text, int $now): array
    {
        $raw = trim($text);
        if ($raw === '') self::fail('', 'it is empty');
        if (preg_match('#^[\d*/,\-]+(\s+[\d*/,\-]+){4}$#', $raw)) { // already a cron expression
            try { Cron::parse($raw); } catch (\InvalidArgumentException $e) { self::fail($raw, $e->getMessage()); }
            return ['cron' => preg_replace('/\s+/', ' ', $raw), 'at' => null];
        }
        $s = ' ' . strtr(mb_strtolower($raw), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '’' => "'", ',' => ' ', '.' => ' ', ';' => ' ']) . ' ';
        $s = preg_replace('/\s+/', ' ', $s);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);

        // "in 20 minutes", "dans 2 heures", "in 3 days"
        if (preg_match('/ (?:in|dans) (\d+|an?|une?) ?(minutes?|mins?|mn|hours?|heures?|h|days?|jours?) /', $s, $m)) {
            $n = ctype_digit($m[1]) ? (int)$m[1] : 1;
            $unit = $m[2][0] === 'm' ? 60 : (in_array($m[2][0], ['h'], true) ? 3600 : 86400);
            if ($n < 1) self::fail($raw, 'the delay must be at least 1');
            return ['cron' => null, 'at' => $now - $now % 60 + $n * $unit];
        }
        // "every 15 minutes", "toutes les 2 heures", "every hour", "hourly"
        if (preg_match('/ (?:every|toutes les|tous les) (\d+) ?(minutes?|mins?|mn|hours?|heures?|h) /', $s, $m)) {
            $n = (int)$m[1];
            if ($m[2][0] === 'm') {
                if ($n < 5 || $n > 59) self::fail($raw, 'a repeat in minutes must be between 5 and 59');
                return ['cron' => "*/$n * * * *", 'at' => null];
            }
            if ($n < 1 || $n > 23) self::fail($raw, 'a repeat in hours must be between 1 and 23');
            return ['cron' => '0 ' . ($n === 1 ? '*' : "*/$n") . ' * * *', 'at' => null];
        }
        if (preg_match('/ (?:every hour|each hour|hourly|toutes les heures|chaque heure) /', $s)) return ['cron' => '0 * * * *', 'at' => null];
        if (preg_match('/ (first|1st|second|2nd|third|3rd|fourth|last|premier|1er|deuxieme|second|troisieme|dernier) (' . self::dayAlt() . ')s? /', $s)) {
            self::fail($raw, 'a weekday within the month (first Monday, last Friday…) cannot be set: give a day of the month, or a weekday every week');
        }

        // the time of day
        [$h, $min, $s] = self::time($s);

        $dayAlt = self::dayAlt();
        $repeat = (bool)preg_match("/ (every|each|daily|weekly|monthly|weekdays|chaque|tous|toutes|quotidien\w*|hebdo\w*|mensuel\w*|en semaine|jours? ouvr\w+|week-?ends?|(?:$dayAlt)s) /", $s);
        // the days of the week named
        $dows = [];
        if (preg_match("/ (?:from )?($dayAlt)s? (?:to|through|-|au|a) ($dayAlt)s? /", $s, $m) || preg_match("/ du ($dayAlt) au ($dayAlt) /", $s, $m)) {
            $a = self::DAYS[$m[1]]; $b = self::DAYS[$m[2]];
            for ($d = $a; ; $d = ($d + 1) % 7) { $dows[$d] = true; if ($d === $b || count($dows) === 7) break; }
            $repeat = true;
        }
        if (preg_match('/ (weekdays?|en semaine|jours? ouvr\w+|working days?|business days?) /', $s)) { foreach ([1, 2, 3, 4, 5] as $d) $dows[$d] = true; $repeat = true; }
        if (preg_match('/ (week-?ends?) /', $s)) { $dows[6] = true; $dows[0] = true; $repeat = true; }
        if (preg_match_all("/ ($dayAlt)s? /", $s, $mm)) foreach ($mm[1] as $d) if (!$dows || !preg_match("/ (?:to|through|au) $d/", $s)) $dows[self::DAYS[$d]] = true;
        ksort($dows);

        // monthly: "on the 15th of every month", "le 1er de chaque mois", "tous les mois le 15", "monthly"
        if (preg_match('/ (monthly|every month|each month|of (?:each|every) month|chaque mois|tous les mois|de chaque mois|mensuel\w*) /', $s)) {
            $dom = preg_match('/ (?:the |le |on )?(\d{1,2}) ?(?:st|nd|rd|th|er|e)? /', $s, $m) ? (int)$m[1] : 1;
            if ($dom < 1 || $dom > 31) self::fail($raw, 'the day of the month must be between 1 and 31');
            if ($dom > 28) self::fail($raw, 'days 29 to 31 are missing from some months: use 28 at the latest');
            return ['cron' => sprintf('%d %d %d * *', $min ?? 0, $h ?? 9, $dom), 'at' => null];
        }
        if ($repeat) {
            if (!$dows && preg_match('/ (weekly|every week|each week|chaque semaine|toutes les semaines|hebdo\w*) /', $s)) {
                self::fail($raw, 'say which day of the week, e.g. "every monday at 9:00"');
            }
            if (!$dows && !preg_match('/ (every day|each day|daily|tous les jours|chaque jour|quotidien\w*|every morning|every evening|chaque (?:matin|soir)|tous les (?:matins|soirs)) /', $s)) {
                self::fail($raw, 'say how often (every day, every monday, every weekday…)');
            }
            $dow = !$dows || count($dows) === 7 ? '*' : self::list(array_keys($dows));
            return ['cron' => sprintf('%d %d * * %s', $min ?? 0, $h ?? 9, $dow), 'at' => null];
        }

        // once: a date, a day word or a weekday
        $date = null;
        if (preg_match('/ (\d{4})-(\d{1,2})-(\d{1,2})(?:t| |$)/', $s, $m)) $date = self::date((int)$m[1], (int)$m[2], (int)$m[3], $raw);
        elseif (preg_match('# (\d{1,2})/(\d{1,2})(?:/(\d{2,4}))? #', $s, $m)) $date = self::date(isset($m[3]) ? (int)(strlen($m[3]) === 2 ? "20$m[3]" : $m[3]) : null, (int)$m[2], (int)$m[1], $raw, $today);
        elseif (preg_match('/ (?:the |le )?(\d{1,2}) ?(?:st|nd|rd|th|er)? (' . implode('|', array_keys(self::MONTHS)) . ')(?: (\d{4}))? /', $s, $m)
            || (preg_match('/ (' . implode('|', array_keys(self::MONTHS)) . ') (\d{1,2})(?:st|nd|rd|th)?(?: (\d{4}))? /', $s, $m2) && ($m = [$m2[0], $m2[2], $m2[1], $m2[3] ?? null])))
            $date = self::date(isset($m[3]) && $m[3] !== null && $m[3] !== '' ? (int)$m[3] : null, self::MONTHS[$m[2]], (int)$m[1], $raw, $today);
        elseif (preg_match('/ (?:day after tomorrow|apres-demain|apres demain) /', $s)) $date = $today->modify('+2 days');
        elseif (preg_match('/ (?:tomorrow|demain) /', $s)) $date = $today->modify('+1 day');
        elseif (preg_match('/ (?:today|aujourd\'hui|aujourd hui|tonight|this (?:morning|afternoon|evening)|ce (?:matin|soir)|cet apres-midi|cet apres midi) /', $s)) $date = $today;
        elseif ($dows) {
            $d = array_key_first($dows);
            $date = $today;
            while ((int)$date->format('w') !== $d || ($date == $today && ($h ?? 9) * 60 + ($min ?? 0) <= (int)date('G', $now) * 60 + (int)date('i', $now))) $date = $date->modify('+1 day');
        } elseif ($h !== null) { // "at 18:00": the next one
            $date = $today;
            if ($h * 60 + ($min ?? 0) <= (int)date('G', $now) * 60 + (int)date('i', $now)) $date = $date->modify('+1 day');
        }
        if ($date === null) self::fail($raw, 'no day or time found');
        $at = $date->setTime($h ?? 9, $min ?? 0)->getTimestamp();
        if ($at < $now - 60) self::fail($raw, 'that time is in the past (now: ' . date('D Y-m-d H:i', $now) . ')');
        return ['cron' => null, 'at' => $at];
    }

    /** The next runs in words, for the model to check: "Mon 13 Oct 08:00, Tue 14 Oct 08:00, Wed 15 Oct 08:00". */
    public static function preview(?string $cron, int $next, int $count = 3): string
    {
        if (!$cron) return 'once, on ' . date('D j M Y H:i', $next);
        $runs = [$next];
        while (count($runs) < $count) $runs[] = Cron::next($cron, end($runs));
        return 'repeats; next runs ' . implode(', ', array_map(fn($t) => date('D j M H:i', $t), $runs)) . ' (cron ' . $cron . ')';
    }

    /** Reads the time of day out of $s and removes it: [hour|null, minute|null, the rest]. */
    private static function time(string $s): array
    {
        $pm = (bool)preg_match('/ (?:du soir|de l\'apres-midi|de l\'apres midi|in the (?:evening|afternoon)|tonight|pm|ce soir) /', $s);
        $part = preg_match('/ (?:morning|matin|matins) /', $s) ? 8 : (preg_match('/ (?:afternoon|apres-midi|apres midi) /', $s) ? 14 : (preg_match('/ (?:evening|evenings|soir|soirs|tonight) /', $s) ? 19 : null));
        $h = $m = null;
        if (preg_match('/ (?:at |a |vers |@ ?)?(\d{1,2}) ?(?::|h) ?(\d{2}) ?(am|pm)? /', $s, $x)) { $h = (int)$x[1]; $m = (int)$x[2]; $ap = $x[3] ?? ''; }
        elseif (preg_match('/ (?:at |a |vers |@ ?)?(\d{1,2}) ?(am|pm) /', $s, $x)) { $h = (int)$x[1]; $m = 0; $ap = $x[2]; }
        elseif (preg_match('/ (?:at |a |vers |@ ?)?(\d{1,2}) ?(?:h|heures?)(?: |$)/', $s, $x)) { $h = (int)$x[1]; $m = 0; $ap = ''; }
        elseif (preg_match('/ (?:at|a|vers|@) (\d{1,2}) (?!(?:st|nd|rd|th|er|e) )/', $s, $x)) { $h = (int)$x[1]; $m = 0; $ap = ''; }
        elseif (preg_match('/ (?:noon|midday|midi) /', $s, $x)) { $h = 12; $m = 0; $ap = ''; }
        elseif (preg_match('/ (?:midnight|minuit) /', $s, $x)) { $h = 0; $m = 0; $ap = ''; }
        if ($h === null) return [$part, $part === null ? null : 0, $s];
        if ($ap === 'pm' && $h < 12) $h += 12;
        elseif ($ap === 'am' && $h === 12) $h = 0;
        elseif ($ap === '' && $pm && $h < 12) $h += 12;
        if ($h > 23 || $m > 59) self::fail(trim($s), 'no such time of day');
        return [$h, $m, str_replace($x[0], ' ', $s)];
    }

    private static function date(?int $y, int $mo, int $d, string $raw, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $guessYear = $y === null;
        $y ??= (int)$today->format('Y');
        if (!checkdate($mo, $d, $y)) self::fail($raw, 'no such date');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->setDate($y, $mo, $d)->setTime(0, 0);
        if ($guessYear && $date < $today) $date = $date->modify('+1 year'); // no year: the next one
        return $date;
    }

    /** 1,2,3,4,5 → 1-5 */
    private static function list(array $days): string
    {
        $out = []; $i = 0;
        while ($i < count($days)) {
            $j = $i;
            while ($j + 1 < count($days) && $days[$j + 1] === $days[$j] + 1) $j++;
            $out[] = $j - $i >= 2 ? "{$days[$i]}-{$days[$j]}" : implode(',', array_slice($days, $i, $j - $i + 1));
            $i = $j + 1;
        }
        return implode(',', $out);
    }

    private static function dayAlt(): string
    {
        $k = array_keys(self::DAYS);
        usort($k, fn($a, $b) => strlen($b) - strlen($a)); // longest first: "thursday" before "thu"
        return implode('|', $k);
    }

    private static function fail(string $raw, string $why): never
    {
        throw new \InvalidArgumentException("could not read the schedule \"$raw\" ($why). Say it in plain words, like " . self::EXAMPLES . '.');
    }
}
