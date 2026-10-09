<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Google Calendar through OAuth (your own OAuth client, created in Google Cloud): reads every calendar
 * you show in Google Calendar, and adds events to your main one after your approval.
 * Tokens are kept in data/google.json (private); "Disconnect" revokes them.
 */
final class Google
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';

    public function __construct(private App $app) {}

    public function configured(): bool { return $this->app->cfg->googleClientId !== '' && $this->app->cfg->googleClientSecret !== ''; }
    public function connected(): bool { return $this->configured() && ($this->tokens()['refresh_token'] ?? '') !== ''; }

    private function file(): string { return $this->app->cfg->dataDir . '/google.json'; }
    public function tokens(): array { return json_decode((string)@file_get_contents($this->file()), true) ?: []; }

    private function saveTokens(array $t): void
    {
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($t, JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    /** Where Google sends the user back; must be listed in the OAuth client exactly like this. */
    public static function redirectUri(): string
    {
        $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']);
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $host) . Config::basePath() . '/api/google/callback';
    }

    /** Start: the address of Google's consent page (a one-time state guards the way back). */
    public function authUrl(string $redirect): string
    {
        if (!$this->configured()) throw new \InvalidArgumentException('Fill in the Google OAuth client ID and secret first (Settings → Calendar).');
        $state = bin2hex(random_bytes(16));
        $this->app->store->set('google_state', json_encode(['state' => $state, 'ts' => time(), 'redirect' => $redirect]));
        return $this->app->cfg->googleAuthUrl . '?' . http_build_query(['client_id' => $this->app->cfg->googleClientId, 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state]);
    }

    /** Back from Google: check the state, trade the code for tokens. */
    public function finish(string $code, string $state): string
    {
        $saved = json_decode($this->app->store->get('google_state'), true);
        $this->app->store->set('google_state', '');
        if (!is_array($saved) || !hash_equals((string)$saved['state'], $state) || $saved['ts'] < time() - 900) throw new \InvalidArgumentException('This sign-in link expired. Press "Connect" again.');
        $t = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $saved['redirect']]);
        if (empty($t['refresh_token'])) throw new \RuntimeException('Google gave no lasting access. Remove Talixio from myaccount.google.com/permissions and connect again.');
        $email = '';
        if (!empty($t['id_token']) && count($p = explode('.', $t['id_token'])) === 3) $email = (string)(json_decode(base64_decode(strtr($p[1], '-_', '+/')), true)['email'] ?? '');
        $this->saveTokens(['refresh_token' => $t['refresh_token'], 'access_token' => $t['access_token'] ?? '', 'expires' => time() + (int)($t['expires_in'] ?? 3000) - 60,
            'email' => $email, 'connected' => time()]);
        return $email;
    }

    public function disconnect(): void
    {
        $t = $this->tokens();
        if (!empty($t['refresh_token'])) {
            try { Http::request('POST', $this->app->cfg->googleRevokeUrl . '?token=' . urlencode($t['refresh_token']), ['Content-Type: application/x-www-form-urlencoded'], '', 15); }
            catch (\Throwable $e) { Log::warn("google revoke: {$e->getMessage()}"); }
        }
        @unlink($this->file());
    }

    private function token(array $params): array
    {
        [$code, $body] = Http::request('POST', $this->app->cfg->googleTokenUrl, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params + ['client_id' => $this->app->cfg->googleClientId, 'client_secret' => $this->app->cfg->googleClientSecret]), 30);
        $d = json_decode($body, true) ?: [];
        if ($code >= 400) {
            if (($d['error'] ?? '') === 'invalid_grant') throw new \RuntimeException('Google access was revoked or expired: connect Google Calendar again in Settings. (In Google Cloud, set the OAuth app to "In production": test apps lose access after 7 days.)');
            throw new \RuntimeException('Google sign-in failed: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code"));
        }
        return $d;
    }

    private function accessToken(): string
    {
        $t = $this->tokens();
        if (empty($t['refresh_token'])) throw new \RuntimeException('Google Calendar is not connected');
        if (($t['access_token'] ?? '') !== '' && ($t['expires'] ?? 0) > time()) return $t['access_token'];
        $n = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
        $t['access_token'] = $n['access_token']; $t['expires'] = time() + (int)($n['expires_in'] ?? 3000) - 60;
        $this->saveTokens($t);
        return $t['access_token'];
    }

    private function api(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->app->cfg->googleApiBase . $path . ($query ? '?' . http_build_query($query) : '');
        [$code, $resp] = Http::request($method, $url, ['Authorization: Bearer ' . $this->accessToken(), 'Content-Type: application/json'],
            $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, 30);
        $d = json_decode($resp, true) ?: [];
        if ($code >= 400) throw new \RuntimeException('Google Calendar: ' . ($d['error']['message'] ?? "HTTP $code"));
        return $d;
    }

    /** Calendars shown in Google Calendar (cached 1 hour). */
    public function calendars(): array
    {
        $cached = json_decode($this->app->store->get('google_calendars'), true);
        if (is_array($cached) && $cached['ts'] > time() - 3600) return $cached['list'];
        $list = [];
        foreach ($this->api('GET', '/users/me/calendarList', null, ['minAccessRole' => 'reader'])['items'] ?? [] as $c) {
            if (($c['selected'] ?? false) || ($c['primary'] ?? false)) $list[] = ['id' => $c['id'], 'name' => $c['summaryOverride'] ?? $c['summary'] ?? $c['id'], 'primary' => !empty($c['primary'])];
        }
        $this->app->store->set('google_calendars', json_encode(['ts' => time(), 'list' => $list]));
        return $list;
    }

    /** Events overlapping [$from, $to), in Calendar's shape. */
    public function events(int $from, int $to): array
    {
        $out = [];
        $cals = $this->calendars();
        foreach ($cals as $c) {
            $page = null;
            do {
                $d = $this->api('GET', '/calendars/' . rawurlencode($c['id']) . '/events', null, array_filter(['timeMin' => gmdate('c', $from), 'timeMax' => gmdate('c', $to),
                    'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250, 'pageToken' => $page]));
                foreach ($d['items'] ?? [] as $e) {
                    if (($e['status'] ?? '') === 'cancelled') continue;
                    if (isset($e['extendedProperties']['private']['talixioTask'])) continue; // our own tasks, shown from the board
                    $allDay = isset($e['start']['date']);
                    $start = $allDay ? strtotime($e['start']['date']) : strtotime((string)($e['start']['dateTime'] ?? ''));
                    $end = $allDay ? strtotime($e['end']['date']) : strtotime((string)($e['end']['dateTime'] ?? ''));
                    if (!$start) continue;
                    $out[] = ['uid' => (string)($e['iCalUID'] ?? $e['id']), 'start' => $start, 'end' => $end ?: $start, 'all_day' => $allDay, 'title' => (string)($e['summary'] ?? '(no title)'),
                        'location' => (string)($e['location'] ?? ''), 'description' => trim(strip_tags(str_replace(['<br>', '<br/>'], "\n", (string)($e['description'] ?? '')))),
                        'calendar' => count($cals) > 1 && !$c['primary'] ? $c['name'] : ''];
                }
                $page = $d['nextPageToken'] ?? null;
            } while ($page);
        }
        return $out;
    }

    /** An all-day event for a task (created, or updated when $id is given). Returns its id. */
    public function putTaskEvent(?string $id, int $cardId, string $title, string $startDay, string $endDay, string $description): string
    {
        $body = ['summary' => $title, 'description' => $description, 'start' => ['date' => $startDay], 'end' => ['date' => date('Y-m-d', strtotime("$endDay +1 day"))],
            'transparency' => 'transparent', 'extendedProperties' => ['private' => ['talixioTask' => (string)$cardId]]];
        if ($id) {
            try { return (string)($this->api('PUT', '/calendars/primary/events/' . rawurlencode($id), $body)['id'] ?? $id); }
            catch (\RuntimeException $e) { if (!preg_match('/not found|deleted|HTTP 404|HTTP 410/i', $e->getMessage())) throw $e; } // removed in Calendar: add it again
        }
        return (string)($this->api('POST', '/calendars/primary/events', $body)['id'] ?? '');
    }

    public function deleteTaskEvent(string $id): void
    {
        try { $this->api('DELETE', '/calendars/primary/events/' . rawurlencode($id)); }
        catch (\RuntimeException $e) { if (!preg_match('/not found|deleted|HTTP 404|HTTP 410/i', $e->getMessage())) throw $e; }
    }

    public function insert(string $title, int $start, int $end, bool $allDay, string $location = '', string $description = ''): array
    {
        $tz = $this->app->cfg->timezone;
        $when = fn(int $t) => $allDay ? ['date' => date('Y-m-d', $t)] : ['dateTime' => date('c', $t), 'timeZone' => $tz];
        return $this->api('POST', '/calendars/primary/events', array_filter(['summary' => $title, 'start' => $when($start), 'end' => $when($end),
            'location' => $location ?: null, 'description' => $description ?: null]));
    }
}
