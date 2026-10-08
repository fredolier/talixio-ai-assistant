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
    public function canWrite(): bool { return $this->app->cfg->caldavUrl !== ''; }

    /** Events overlapping [$from, $to). */
    public function events(int $from, int $to): array
    {
        $c = $this->app->cfg;
        $all = [];
        foreach ($c->calendarIcs as $i => $url) {
            $all = array_merge($all, ICal::events($this->ics($url), $from, $to, count($c->calendarIcs) > 1 || $this->canWrite() ? 'calendar ' . ($i + 1) : ''));
        }
        if ($this->canWrite()) {
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

    /** Create an event (called after approval). */
    public function add(string $title, int $start, int $end, bool $allDay, string $location = '', string $description = ''): string
    {
        if (!$this->canWrite()) throw new \RuntimeException('no writable calendar: set TALIXIO_CALDAV_URL');
        $uid = bin2hex(random_bytes(12)) . '@talixio';
        $esc = fn(string $v) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $v);
        $dt = fn(int $t) => $allDay ? ';VALUE=DATE:' . date('Ymd', $t) : ':' . gmdate('Ymd\THis\Z', $t);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Talixio//EN', 'BEGIN:VEVENT', "UID:$uid", 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART' . $dt($start), 'DTEND' . $dt($end), 'SUMMARY:' . $esc($title)];
        if ($location !== '') $lines[] = 'LOCATION:' . $esc($location);
        if ($description !== '') $lines[] = 'DESCRIPTION:' . $esc($description);
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        $url = rtrim($this->app->cfg->caldavUrl, '/') . '/' . $uid . '.ics';
        [$code, $body] = Http::request('PUT', $url, array_merge($this->auth(), ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *']),
            implode("\r\n", $lines) . "\r\n", 30);
        if ($code >= 300) throw new \RuntimeException("CalDAV PUT HTTP $code: " . mb_substr(strip_tags($body), 0, 200));
        return "added to the calendar: $title, " . ($allDay ? date('D j M', $start) . ' (all day)' : date('D j M H:i', $start) . '–' . date('H:i', $end));
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
