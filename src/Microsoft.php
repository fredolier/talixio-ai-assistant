<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Outlook / Microsoft 365 calendar through Microsoft Graph (your own app, registered in Microsoft Entra):
 * reads your main calendar, adds events to it after your approval and keeps the tasks shown in the calendar there.
 * Tokens are kept in data/microsoft.json (private); "Disconnect" deletes them.
 * The same app also signs in to a mailbox for SMTP and IMAP (OAuth, where Microsoft no longer takes passwords):
 * one connection per purpose, 'email' (the assistant's own) and 'mailbox' (yours), in data/microsoft-<purpose>.json.
 */
final class Microsoft
{
    public const SCOPES = 'offline_access openid email User.Read Calendars.ReadWrite';
    public const MAIL_SCOPES = 'offline_access openid email https://outlook.office.com/SMTP.Send https://outlook.office.com/IMAP.AccessAsUser.All';
    public const PURPOSES = ['', 'email', 'mailbox'];
    /** Marks the events made for tasks (a named property of the event, invisible in Outlook). */
    private const TASK_PROP = 'String {00020329-0000-0000-C000-000000000046} Name talixioTask';

    /** $purpose: '' for the calendar, or the mail connection ('email', 'mailbox'). */
    public function __construct(private App $app, private string $purpose = '')
    {
        if (!in_array($purpose, self::PURPOSES, true)) throw new \InvalidArgumentException('Unknown Microsoft connection');
    }

    private function scopes(): string { return $this->purpose === '' ? self::SCOPES : self::MAIL_SCOPES; }

    /** SMTP/IMAP sign-in for $purpose when its settings ask for the Microsoft account: the user and a token source. */
    /** @var string[] servers a mail token may be sent to (and their subdomains) */
    public static array $mailHosts = ['office365.com', 'outlook.com'];

    public static function mailLogin(App $app, string $purpose, string $user, string $host): ?array
    {
        if (($purpose === 'email' ? $app->cfg->smtpAuth : $app->cfg->mailboxAuth) !== 'microsoft') return null;
        $host = strtolower(rtrim($host, '.'));
        if (!array_filter(self::$mailHosts, fn($h) => $host === $h || str_ends_with($host, ".$h"))) // never hand the account's token to another server
            throw new \RuntimeException("Microsoft sign-in only works with Microsoft 365's servers (smtp.office365.com, outlook.office365.com), not $host: change the server in the email settings.");
        $ms = new self($app, $purpose);
        return [$user !== '' ? $user : (string)($ms->tokens()['email'] ?? ''), fn() => $ms->accessToken()];
    }

    public function configured(): bool { return $this->app->cfg->microsoftClientId !== '' && $this->app->cfg->microsoftClientSecret !== ''; }
    public function connected(): bool { return $this->configured() && ($this->tokens()['refresh_token'] ?? '') !== ''; }

    private function file(): string { return $this->app->cfg->dataDir . ($this->purpose === '' ? '/microsoft.json' : "/microsoft-{$this->purpose}.json"); }
    public function tokens(): array { return json_decode((string)@file_get_contents($this->file()), true) ?: []; }

    private function saveTokens(array $t): void
    {
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($t, JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    /** Where Microsoft sends the user back; must be listed in the app registration exactly like this. */
    public static function redirectUri(string $publicUrl = ''): string { return preg_replace('#/api/google/callback$#', '/api/microsoft/callback', Google::redirectUri($publicUrl)); }

    private function login(string $what): string
    {
        $tenant = preg_replace('/[^A-Za-z0-9.-]/', '', $this->app->cfg->microsoftTenant) ?: 'common';
        return $this->app->cfg->microsoftLoginBase . '/' . $tenant . '/oauth2/v2.0/' . $what;
    }

    /** Start: the address of Microsoft's sign-in page (a one-time state guards the way back). */
    public function authUrl(string $redirect): string
    {
        if (!$this->configured()) throw new \InvalidArgumentException('Fill in the Microsoft application ID and secret first (Settings → Calendar).');
        $state = bin2hex(random_bytes(16));
        $this->app->store->set('microsoft_state', json_encode(['state' => $state, 'ts' => time(), 'redirect' => $redirect, 'purpose' => $this->purpose]));
        return $this->login('authorize') . '?' . http_build_query(['client_id' => $this->app->cfg->microsoftClientId, 'redirect_uri' => $redirect,
            'response_type' => 'code', 'response_mode' => 'query', 'scope' => $this->scopes(), 'prompt' => 'select_account', 'state' => $state]);
    }

    /** The connection the last sign-in was started for (set by finish()). */
    public string $finished = '';

    /** Back from Microsoft: check the state, trade the code for tokens (kept for the connection the sign-in was started for).
     *  $error: what Microsoft sent back instead of a code. */
    public function finish(string $code, string $state, string $error = ''): string
    {
        $saved = json_decode($this->app->store->get('microsoft_state'), true);
        $this->app->store->set('microsoft_state', '');
        $for = is_array($saved) && in_array($saved['purpose'] ?? '', self::PURPOSES, true) ? (string)$saved['purpose'] : '';
        $this->finished = $for; // the failure is shown with that connection too
        if ($error !== '') throw new \RuntimeException('Microsoft: ' . strtok($error, "\r\n"));
        if (!is_array($saved) || !hash_equals((string)$saved['state'], $state) || $saved['ts'] < time() - 900) throw new \InvalidArgumentException('This sign-in link expired. Press "Connect" again.');
        return ($for === $this->purpose ? $this : new self($this->app, $for))->finishWith($code, $saved['redirect']);
    }

    private function errorKey(): string { return 'microsoft_error' . ($this->purpose === '' ? '' : "_{$this->purpose}"); }
    /** Why the last sign-in failed ("Y-m-d H:i message"), until one succeeds. */
    public function lastError(): string { return (string)$this->app->store->get($this->errorKey()); }
    public function failed(string $message): void { $this->app->store->set($this->errorKey(), date('Y-m-d H:i') . ' ' . $message); }

    private function finishWith(string $code, string $redirect): string
    {
        $t = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect, 'scope' => $this->scopes()]);
        if (empty($t['refresh_token'])) throw new \RuntimeException('Microsoft gave no lasting access: check that the app asks for offline_access, then connect again.');
        $email = '';
        if (!empty($t['id_token']) && count($p = explode('.', $t['id_token'])) === 3) {
            $claims = json_decode(base64_decode(strtr($p[1], '-_', '+/')), true) ?: [];
            $email = (string)($claims['email'] ?? $claims['preferred_username'] ?? '');
        }
        $this->saveTokens(['refresh_token' => $t['refresh_token'], 'access_token' => $t['access_token'] ?? '', 'expires' => time() + (int)($t['expires_in'] ?? 3000) - 60,
            'email' => $email, 'connected' => time()]);
        $this->app->store->set($this->errorKey(), '');
        return $email;
    }

    /** Microsoft has no revocation call for a single token: forgetting it is enough (remove the app at myapps.microsoft.com to revoke). */
    public function disconnect(): void { @unlink($this->file()); $this->app->store->set($this->errorKey(), ''); }

    private function token(array $params): array
    {
        [$code, $body] = Http::request('POST', $this->login('token'), ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params + ['client_id' => $this->app->cfg->microsoftClientId, 'client_secret' => $this->app->cfg->microsoftClientSecret]), 30);
        $d = json_decode($body, true) ?: [];
        if ($code >= 400) {
            if (($d['error'] ?? '') === 'invalid_grant') throw new \RuntimeException($this->purpose === '' ? 'Outlook access was revoked or expired (or the app secret expired): connect Outlook again in Settings.'
                : 'Microsoft mail access was revoked or expired (or the app secret expired): connect the mailbox again in Settings.');
            throw new \RuntimeException('Microsoft sign-in failed: ' . strtok((string)($d['error_description'] ?? $d['error'] ?? "HTTP $code"), "\r\n"));
        }
        return $d;
    }

    public function accessToken(): string
    {
        $t = $this->tokens();
        if (empty($t['refresh_token'])) throw new \RuntimeException($this->purpose === '' ? 'Outlook is not connected' : 'The Microsoft account is not connected: press "Connect with Microsoft" under its Sign-in setting.');
        if (($t['access_token'] ?? '') !== '' && ($t['expires'] ?? 0) > time()) return $t['access_token'];
        $n = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'scope' => $this->scopes()]);
        $t['access_token'] = $n['access_token'];
        $t['expires'] = time() + (int)($n['expires_in'] ?? 3000) - 60;
        if (!empty($n['refresh_token'])) $t['refresh_token'] = $n['refresh_token']; // Microsoft rolls it
        $this->saveTokens($t);
        return $t['access_token'];
    }

    /** $path: under the Graph base, or a full address (next pages). */
    private function api(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = (str_starts_with($path, 'http') ? $path : $this->app->cfg->microsoftApiBase . $path) . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        [$code, $resp] = Http::request($method, $url, ['Authorization: Bearer ' . $this->accessToken(), 'Content-Type: application/json', 'Prefer: outlook.timezone="UTC"'],
            $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 30);
        $d = json_decode($resp, true) ?: [];
        if ($code >= 400) throw new \RuntimeException('Outlook: ' . ($d['error']['message'] ?? '') . " (HTTP $code)");
        return $d;
    }

    /** Events of the main calendar overlapping [$from, $to), in Calendar's shape. */
    public function events(int $from, int $to): array
    {
        $out = [];
        $next = '/me/calendarView';
        $query = ['startDateTime' => gmdate('Y-m-d\TH:i:s\Z', $from), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', $to), '$top' => 250,
            '$select' => 'iCalUId,id,subject,start,end,isAllDay,isCancelled,location,bodyPreview,showAs',
            '$expand' => "singleValueExtendedProperties(\$filter=id eq '" . self::TASK_PROP . "')"];
        for ($i = 0; $next && $i < 20; $i++) {
            $d = $this->api('GET', $next, null, $query);
            foreach ($d['value'] ?? [] as $e) {
                if (!empty($e['isCancelled'])) continue;
                if (!empty($e['singleValueExtendedProperties'])) continue; // our own tasks, shown from the board
                $allDay = !empty($e['isAllDay']);
                $at = fn(array $w) => $allDay ? strtotime(substr((string)($w['dateTime'] ?? ''), 0, 10)) : strtotime(substr((string)($w['dateTime'] ?? ''), 0, 19) . 'Z');
                $start = $at($e['start'] ?? []); $end = $at($e['end'] ?? []);
                if (!$start) continue;
                $out[] = ['uid' => (string)($e['iCalUId'] ?? $e['id']), 'start' => $start, 'end' => $end ?: $start, 'all_day' => $allDay, 'title' => (string)($e['subject'] ?? '') ?: '(no title)',
                    'location' => (string)($e['location']['displayName'] ?? ''), 'description' => trim((string)($e['bodyPreview'] ?? '')), 'calendar' => ''];
            }
            $next = $d['@odata.nextLink'] ?? null; $query = []; // the next address carries its query
        }
        return $out;
    }

    /** Graph's date shape: the local time and its zone. */
    private function when(int $t, bool $allDay): array
    {
        return ['dateTime' => $allDay ? date('Y-m-d\T00:00:00', $t) : date('Y-m-d\TH:i:s', $t), 'timeZone' => $this->app->cfg->timezone];
    }

    /** An event for a task (created, or updated when $id is given). Returns its id. $slot: [from, to] for a timed task (a meeting, shown as busy); null for all-day. */
    public function putTaskEvent(?string $id, int $cardId, string $title, string $startDay, string $endDay, string $description, ?array $slot = null, string $location = ''): string
    {
        $body = ['subject' => $title, 'body' => ['contentType' => 'text', 'content' => $description], 'location' => ['displayName' => $location],
            'singleValueExtendedProperties' => [['id' => self::TASK_PROP, 'value' => (string)$cardId]]]
            + ($slot ? ['start' => $this->when($slot[0], false), 'end' => $this->when($slot[1], false), 'isAllDay' => false, 'showAs' => 'busy']
                : ['start' => $this->when(strtotime($startDay), true), 'end' => $this->when(strtotime("$endDay +1 day"), true), 'isAllDay' => true, 'showAs' => 'free']);
        if ($id) {
            try { return (string)($this->api('PATCH', '/me/events/' . rawurlencode($id), $body)['id'] ?? $id); }
            catch (\RuntimeException $e) { if (!preg_match('/not found|HTTP 404|HTTP 410/i', $e->getMessage())) throw $e; } // removed in Outlook: add it again
        }
        return (string)($this->api('POST', '/me/events', $body)['id'] ?? '');
    }

    public function deleteTaskEvent(string $id): void
    {
        try { $this->api('DELETE', '/me/events/' . rawurlencode($id)); }
        catch (\RuntimeException $e) { if (!preg_match('/not found|HTTP 404|HTTP 410/i', $e->getMessage())) throw $e; }
    }

    /** $opt: see Calendar::options() — Outlook sends the invitations itself; the days left out are removed from the series once made. */
    public function insert(string $title, int $start, int $end, bool $allDay, string $location = '', string $description = '', array $opt = []): array
    {
        if ($allDay && $end <= $start) $end = strtotime('+1 day', $start);
        $rec = null;
        if (!empty($opt['repeat'])) {
            $day = strtolower(date('l', $start));
            $rec = ['pattern' => match ($opt['repeat']) {
                'daily' => ['type' => 'daily', 'interval' => 1],
                'weekdays' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'firstDayOfWeek' => 'monday'],
                'weekly' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [$day], 'firstDayOfWeek' => 'monday'],
                'monthly' => ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => (int)date('j', $start)],
            }, 'range' => ($opt['until'] ?? '') !== '' ? ['type' => 'endDate', 'startDate' => date('Y-m-d', $start), 'endDate' => $opt['until']]
                : ['type' => 'noEnd', 'startDate' => date('Y-m-d', $start)]];
        }
        $ev = $this->api('POST', '/me/events', array_filter(['subject' => $title, 'start' => $this->when($start, $allDay), 'end' => $this->when($end, $allDay), 'isAllDay' => $allDay,
            'location' => $location !== '' ? ['displayName' => $location] : null, 'body' => $description !== '' ? ['contentType' => 'text', 'content' => $description] : null,
            'recurrence' => $rec, 'attendees' => array_map(fn($a) => ['emailAddress' => ['address' => $a], 'type' => 'required'], $opt['attendees'] ?? []) ?: null], fn($v) => $v !== null));
        if (!empty($opt['skip']) && !empty($ev['id'])) {
            // Graph makes a series without exceptions: the occurrences on the days left out are deleted (the guests get a cancellation for those days only)
            $skip = array_flip($opt['skip']);
            $next = '/me/events/' . rawurlencode($ev['id']) . '/instances';
            $query = ['startDateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime(min($opt['skip']))), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime(max($opt['skip']) . ' +1 day')),
                '$select' => 'id,start', '$top' => 250];
            for ($i = 0; $next && $i < 10; $i++) {
                $d = $this->api('GET', $next, null, $query);
                foreach ($d['value'] ?? [] as $o) {
                    $at = $allDay ? substr((string)($o['start']['dateTime'] ?? ''), 0, 10) : date('Y-m-d', strtotime(substr((string)($o['start']['dateTime'] ?? ''), 0, 19) . 'Z'));
                    if (isset($skip[$at])) $this->api('DELETE', '/me/events/' . rawurlencode((string)$o['id']));
                }
                $next = $d['@odata.nextLink'] ?? null; $query = [];
            }
        }
        return $ev;
    }
}
