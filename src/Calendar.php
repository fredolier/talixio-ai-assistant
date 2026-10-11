<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Your calendars. Reading: any iCal subscription link (Google "secret address", Outlook, iCloud
 * public link...) and/or a CalDAV calendar. Adding events: CalDAV (Nextcloud, Fastmail, iCloud,
 * Zimbra...), always after your approval.
 */
final class Calendar
{
    public function __construct(private App $app) {}

    public function enabled(): bool { return $this->app->cfg->calendarIcs !== [] || $this->canWrite(); }
    public function canWrite(): bool { return $this->app->cfg->caldavUrl !== '' || $this->app->google()->connected() || $this->app->microsoft()->connected(); }
    private function caldav(): bool { return $this->app->cfg->caldavUrl !== ''; }

    /** Events overlapping [$from, $to). */
    public function events(int $from, int $to): array
    {
        $c = $this->app->cfg;
        $all = [];
        foreach ($c->calendarIcs as $i => $url) {
            $all = array_merge($all, ICal::events($this->ics($url), $from, $to, count($c->calendarIcs) > 1 || $this->canWrite() ? 'calendar ' . ($i + 1) : ''));
        }
        if ($this->app->google()->connected()) $all = array_merge($this->app->google()->events($from, $to), $all); // first: its names win in duplicates
        if ($this->app->microsoft()->connected()) $all = array_merge($this->app->microsoft()->events($from, $to), $all);
        if ($this->caldav()) {
            foreach ($this->caldavQuery($from, $to) as $ics) $all = array_merge($all, ICal::events($ics, $from, $to, $c->calendarIcs ? 'CalDAV' : ''));
        }
        $seen = []; // same event in two sources
        $all = array_values(array_filter($all, function ($e) use (&$seen) { $k = $e['uid'] . '@' . $e['start']; if (isset($seen[$k])) return false; return $seen[$k] = true; }));
        usort($all, fn($a, $b) => $a['start'] <=> $b['start']);
        return $all;
    }

    public static function format(array $events): string
    {
        if (!$events) return 'No events.';
        return implode("\n", array_map(function ($e) {
            $day = date('D j M', $e['start']);
            $when = $e['all_day'] ? "$day (all day)" : $day . ' ' . date('H:i', $e['start']) . '–' . date(date('Ymd', $e['start']) === date('Ymd', $e['end']) ? 'H:i' : 'D j M H:i', $e['end']);
            return "- $when: {$e['title']}" . ($e['location'] !== '' ? " @ {$e['location']}" : '') . ($e['calendar'] !== '' ? " [{$e['calendar']}]" : '')
                . ($e['description'] !== '' ? "\n  " . preg_replace('/\s+/', ' ', mb_substr($e['description'], 0, 200)) : '');
        }, $events));
    }

    /** iCal feed, cached for 5 minutes. */
    private function ics(string $url): string
    {
        $key = 'ics_' . md5($url);
        $cached = json_decode($this->app->store->get($key), true);
        if (is_array($cached) && $cached['ts'] > time() - 300) return $cached['body'];
        $u = preg_replace('#^webcal://#i', 'https://', $url);
        [$code, $body] = Http::request('GET', $u, ['Accept: text/calendar'], null, 30, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5]);
        if ($code >= 400 || !str_contains($body, 'BEGIN:VCALENDAR')) throw new \RuntimeException("calendar feed HTTP $code");
        $this->app->store->set($key, json_encode(['ts' => time(), 'body' => $body]));
        return $body;
    }

    private function auth(): array
    {
        $c = $this->app->cfg;
        return ['Authorization: Basic ' . base64_encode("{$c->caldavUser}:{$c->caldavPassword}")];
    }

    /** CalDAV calendar-query REPORT: raw iCalendar objects in the range. */
    private function caldavQuery(int $from, int $to): array
    {
        $fmt = fn(int $t) => gmdate('Ymd\THis\Z', $t);
        $xml = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . '<c:time-range start="' . $fmt($from) . '" end="' . $fmt($to) . '"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        [$code, $body] = Http::request('REPORT', $this->app->cfg->caldavUrl, array_merge($this->auth(), ['Depth: 1', 'Content-Type: application/xml; charset=utf-8']), $xml, 30);
        if ($code >= 400) throw new \RuntimeException("CalDAV HTTP $code" . ($code === 401 ? ' (check TALIXIO_CALDAV_USER/PASSWORD)' : ''));
        preg_match_all('#<(?:[\w-]+:)?calendar-data[^>]*>(.*?)</(?:[\w-]+:)?calendar-data>#s', $body, $m);
        return array_map(fn($x) => html_entity_decode(preg_replace('#^<!\[CDATA\[|\]\]>$#', '', trim($x)), ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
    }

    /**
     * Create an event (called after approval). $opt: attendees (emails, invited by the calendar), repeat (daily | weekdays | weekly |
     * monthly), until (YYYY-MM-DD, last day of the series), skip (dates of occurrences left out), as given by Calendar::options().
     */
    public function add(string $title, int $start, int $end, bool $allDay, string $location = '', string $description = '', array $opt = []): string
    {
        if (!$this->canWrite()) throw new \RuntimeException('no writable calendar: connect Google Calendar or Outlook, or set a CalDAV calendar');
        $when = ($allDay ? date('D j M', $start) . ' (all day)' : date('D j M H:i', $start) . '–' . date('H:i', $end)) . self::describe($opt);
        if ($this->app->google()->connected()) {
            $this->app->google()->insert($title, $start, $end, $allDay, $location, $description, $opt);
            return "added to Google Calendar: $title, $when" . (!empty($opt['attendees']) ? '; Google sent the invitations' : '');
        }
        if ($this->app->microsoft()->connected()) {
            $this->app->microsoft()->insert($title, $start, $end, $allDay, $location, $description, $opt);
            return "added to Outlook: $title, $when" . (!empty($opt['attendees']) ? '; Outlook sent the invitations' : '');
        }
        $uid = bin2hex(random_bytes(12)) . '@talixio';
        $esc = fn(string $v) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $v);
        $dt = fn(int $t) => $allDay ? ';VALUE=DATE:' . date('Ymd', $t) : ':' . gmdate('Ymd\THis\Z', $t);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Talixio//EN', 'BEGIN:VEVENT', "UID:$uid", 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART' . $dt($start), 'DTEND' . $dt($end), 'SUMMARY:' . $esc($title)];
        if ($location !== '') $lines[] = 'LOCATION:' . $esc($location);
        if ($description !== '') $lines[] = 'DESCRIPTION:' . $esc($description);
        if (!empty($opt['repeat'])) {
            $lines[] = 'RRULE:' . self::rrule($opt, $allDay);
            foreach ($opt['skip'] ?? [] as $d) $lines[] = 'EXDATE' . $dt(self::sameTime($start, $d));
        }
        foreach ($opt['attendees'] ?? [] as $a) $lines[] = "ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:$a";
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        $url = rtrim($this->app->cfg->caldavUrl, '/') . '/' . $uid . '.ics';
        [$code, $body] = Http::request('PUT', $url, array_merge($this->auth(), ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *']),
            implode("\r\n", $lines) . "\r\n", 30);
        if ($code >= 300) throw new \RuntimeException("CalDAV PUT HTTP $code: " . mb_substr(strip_tags($body), 0, 200));
        return "added to the calendar: $title, $when" . (!empty($opt['attendees']) ? '; guests are listed in the event (whether they get an email depends on your calendar server)' : '');
    }

    public const REPEATS = ['daily', 'weekdays', 'weekly', 'monthly'];

    /**
     * Checks and completes what the assistant asked for: guests (emails), repetition, end of the series, days left out
     * (the dates given, and the public holidays when $holidays) — only those that fall on an occurrence are kept.
     */
    public static function options(array $a, int $start): array
    {
        $opt = [];
        $guests = is_array($a['attendees'] ?? null) ? $a['attendees'] : preg_split('/[\s,;]+/', (string)($a['attendees'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($guests as $g) {
            $g = trim((string)$g, " <>\"'");
            if (!filter_var($g, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException("guest '$g' is not an email address: find it with contact_find, or ask the user");
            $opt['attendees'][] = strtolower($g);
        }
        if (isset($opt['attendees'])) $opt['attendees'] = array_values(array_unique($opt['attendees']));
        $repeat = strtolower(trim((string)($a['repeat'] ?? '')));
        if ($repeat === '' || $repeat === 'none') return $opt;
        if (!in_array($repeat, self::REPEATS, true)) throw new \InvalidArgumentException("repeat: one of " . implode(', ', self::REPEATS));
        $until = trim((string)($a['until'] ?? ''));
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < date('Y-m-d', $start))) throw new \InvalidArgumentException("until: the last day of the series, YYYY-MM-DD, not before the start");
        $opt += ['repeat' => $repeat, 'until' => $until];
        $dates = self::occurrences($start, $repeat, $until);
        $skip = is_array($a['skip'] ?? null) ? $a['skip'] : preg_split('/[\s,;]+/', (string)($a['skip'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($a['skip_holidays'])) foreach (array_unique(array_map(fn($d) => (int)substr($d, 0, 4), $dates)) as $y) $skip = array_merge($skip, array_keys(self::holidays($y)));
        $opt['skip'] = array_values(array_intersect($dates, $skip));
        if (count($opt['skip']) >= count($dates)) throw new \InvalidArgumentException('every occurrence would be left out');
        return $opt;
    }

    /** The days of the series (at most a year when it has no end, at most 400). */
    public static function occurrences(int $start, string $repeat, string $until): array
    {
        $d = new \DateTimeImmutable(date('Y-m-d', $start));
        $last = $until !== '' ? $until : $d->modify('+1 year')->format('Y-m-d');
        $out = [];
        for ($i = 0; count($out) < 400; $i++) {
            $day = match ($repeat) { 'daily', 'weekdays' => $d->modify("+$i day"), 'weekly' => $d->modify('+' . (7 * $i) . ' day'), 'monthly' => $d->modify("+$i month") };
            if ($repeat === 'monthly' && $day->format('d') !== $d->format('d')) continue; // the 31st: months without it are passed, as calendars do
            if ($day->format('Y-m-d') > $last) break;
            if ($repeat === 'weekdays' && (int)$day->format('N') > 5) continue;
            $out[] = $day->format('Y-m-d');
        }
        return $out;
    }

    /** French public holidays of a year: [YYYY-MM-DD => name]. */
    public static function holidays(int $y): array
    {
        // Easter Sunday (anonymous Gregorian algorithm)
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $easter = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $y, intdiv($h + $l - 7 * $m + 114, 31), ($h + $l - 7 * $m + 114) % 31 + 1));
        $h = ["$y-01-01" => "New Year's Day", "$y-05-01" => 'Labour Day', "$y-05-08" => 'Victory in Europe Day', "$y-07-14" => 'Bastille Day',
            "$y-08-15" => 'Assumption', "$y-11-01" => "All Saints' Day", "$y-11-11" => 'Armistice Day', "$y-12-25" => 'Christmas',
            $easter->modify('+1 day')->format('Y-m-d') => 'Easter Monday', $easter->modify('+39 day')->format('Y-m-d') => 'Ascension Day',
            $easter->modify('+50 day')->format('Y-m-d') => 'Whit Monday'];
        ksort($h);
        return $h;
    }

    /** The iCalendar rule of the series (UNTIL in UTC at the end of the last day, or as a date for all-day events). */
    public static function rrule(array $opt, bool $allDay): string
    {
        $r = match ($opt['repeat']) { 'daily' => 'FREQ=DAILY', 'weekdays' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'weekly' => 'FREQ=WEEKLY', 'monthly' => 'FREQ=MONTHLY' };
        if (($opt['until'] ?? '') !== '') $r .= ';UNTIL=' . ($allDay ? str_replace('-', '', $opt['until']) : gmdate('Ymd\THis\Z', strtotime($opt['until'] . ' 23:59:59')));
        return $r;
    }

    /** The occurrence on day $d: same local time as $start. */
    public static function sameTime(int $start, string $d): int { return strtotime($d . ' ' . date('H:i:s', $start)); }

    /** ", every Monday until 2026-12-24, without 11 Nov; guests: a@x, b@y" — for the approval and the result. */
    public static function describe(array $opt): string
    {
        $s = '';
        if (!empty($opt['repeat'])) {
            $s .= ', ' . ['daily' => 'every day', 'weekdays' => 'every weekday', 'weekly' => 'every week', 'monthly' => 'every month'][$opt['repeat']]
                . ($opt['until'] !== '' ? " until {$opt['until']}" : '') . ($opt['skip'] ? ', except ' . implode(', ', $opt['skip']) : '');
        }
        if (!empty($opt['attendees'])) $s .= '; guests: ' . implode(', ', $opt['attendees']);
        return $s;
    }

    /** "2026-10-12 14:00" or "2026-10-12" in the user's timezone. */
    public static function parseTime(string $v): array
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return [(new \DateTimeImmutable($v))->getTimestamp(), true];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $v) ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v);
        if (!$d) throw new \InvalidArgumentException("bad time '$v': use YYYY-MM-DD HH:MM or YYYY-MM-DD");
        return [$d->getTimestamp(), false];
    }
}
