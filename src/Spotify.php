<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Spotify through its Web API (your own app, created on developer.spotify.com; Premium needed to control playback):
 * play a song, an artist, an album or a playlist on your phone or a speaker, pause, skip, volume, queue,
 * save to a playlist. Spotify must be open (or recently used) on the device for it to be listed.
 * Tokens are kept in data/spotify.json (private).
 */
final class Spotify
{
    public const SCOPES = 'user-read-playback-state user-modify-playback-state user-read-currently-playing user-read-private playlist-read-private playlist-modify-private playlist-modify-public user-library-modify';

    public function __construct(private App $app) {}

    public function configured(): bool { return $this->app->cfg->spotifyClientId !== '' && $this->app->cfg->spotifyClientSecret !== ''; }
    public function connected(): bool { return $this->configured() && ($this->tokens()['refresh_token'] ?? '') !== ''; }

    private function file(): string { return $this->app->cfg->dataDir . '/spotify.json'; }
    public function tokens(): array { return json_decode((string)@file_get_contents($this->file()), true) ?: []; }

    private function saveTokens(array $t): void
    {
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($t, JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    public static function redirectUri(string $publicUrl = ''): string { return preg_replace('#/api/google/callback$#', '/api/spotify/callback', Google::redirectUri($publicUrl)); }

    public function authUrl(string $redirect): string
    {
        if (!$this->configured()) throw new \InvalidArgumentException('Fill in the Spotify client ID and secret first (Settings → Connected accounts → Spotify).');
        $state = bin2hex(random_bytes(16));
        $this->app->store->set('spotify_state', json_encode(['state' => $state, 'ts' => time(), 'redirect' => $redirect]));
        return $this->app->cfg->spotifyAuthUrl . '?' . http_build_query(['client_id' => $this->app->cfg->spotifyClientId, 'response_type' => 'code',
            'redirect_uri' => $redirect, 'scope' => self::SCOPES, 'state' => $state, 'show_dialog' => 'true']);
    }

    /** Back from Spotify: check the state, trade the code for tokens. Returns the account name. */
    public function finish(string $code, string $state): string
    {
        $saved = json_decode($this->app->store->get('spotify_state'), true);
        $this->app->store->set('spotify_state', '');
        if (!is_array($saved) || !hash_equals((string)$saved['state'], $state) || $saved['ts'] < time() - 900) throw new \InvalidArgumentException('This sign-in link expired. Press "Connect" again.');
        $t = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $saved['redirect']]);
        if (empty($t['refresh_token'])) throw new \RuntimeException('Spotify gave no lasting access. Try connecting again.');
        $this->saveTokens(['refresh_token' => $t['refresh_token'], 'access_token' => $t['access_token'] ?? '', 'expires' => time() + (int)($t['expires_in'] ?? 3600) - 60, 'connected' => time()]);
        $name = '';
        try { $me = $this->api('GET', '/me'); $name = (string)($me['display_name'] ?? $me['id'] ?? ''); $premium = ($me['product'] ?? 'premium') === 'premium'; }
        catch (\Throwable $e) { Log::warn("spotify me: {$e->getMessage()}"); }
        $t = $this->tokens(); $t['name'] = $name; $t['premium'] = $premium ?? null;
        $this->saveTokens($t);
        return $name;
    }

    /** Spotify has no revoke call: the tokens are forgotten here (remove the app at spotify.com/account/apps to cut it there too). */
    public function disconnect(): void { @unlink($this->file()); }

    private function token(array $params): array
    {
        $c = $this->app->cfg;
        [$code, $body] = Http::request('POST', $c->spotifyTokenUrl, ['Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($c->spotifyClientId . ':' . $c->spotifyClientSecret)], http_build_query($params), 30);
        $d = json_decode($body, true) ?: [];
        if ($code >= 400) {
            if (($d['error'] ?? '') === 'invalid_grant') throw new \RuntimeException('Spotify access was revoked or expired: connect Spotify again in Settings.');
            throw new \RuntimeException('Spotify sign-in failed: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code"));
        }
        return $d;
    }

    private function accessToken(): string
    {
        $t = $this->tokens();
        if (empty($t['refresh_token'])) throw new \RuntimeException('Spotify is not connected (Settings → Spotify).');
        if (($t['access_token'] ?? '') !== '' && ($t['expires'] ?? 0) > time()) return $t['access_token'];
        $n = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
        $t['access_token'] = $n['access_token']; $t['expires'] = time() + (int)($n['expires_in'] ?? 3600) - 60;
        if (!empty($n['refresh_token'])) $t['refresh_token'] = $n['refresh_token']; // Spotify may rotate it
        $this->saveTokens($t);
        return $t['access_token'];
    }

    /** @return array decoded body ([] for "204 No Content") */
    private function api(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->app->cfg->spotifyApiBase . $path . ($query ? '?' . http_build_query($query) : '');
        [$code, $resp] = Http::request($method, $url, ['Authorization: Bearer ' . $this->accessToken(), 'Content-Type: application/json'],
            $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($method === 'GET' ? null : ''), 20);
        $d = json_decode($resp, true);
        if ($code >= 400) {
            $err = $d['error'] ?? [];
            $reason = is_array($err) ? ($err['reason'] ?? '') : '';
            $msg = is_array($err) ? ($err['message'] ?? "HTTP $code") : (string)$err;
            if ($reason === 'PREMIUM_REQUIRED' || ($code === 403 && stripos($msg, 'premium') !== false)) throw new SpotifyError('Controlling playback needs Spotify Premium.', 'premium');
            if ($reason === 'NO_ACTIVE_DEVICE' || ($code === 404 && stripos($msg, 'device') !== false)) throw new SpotifyError('No Spotify device is available.', 'device');
            throw new SpotifyError("Spotify: $msg", 'other', $code);
        }
        return is_array($d) ? $d : [];
    }

    public function devices(): array { return $this->api('GET', '/me/player/devices')['devices'] ?? []; }

    /** The device to play on: the one named, else the one playing, else your usual one, else a phone, else any. */
    public function pickDevice(string $name = ''): ?array
    {
        $list = $this->devices();
        if (!$list) return null;
        $find = function (string $n) use ($list) {
            $n = mb_strtolower(trim($n));
            if ($n === '') return null;
            foreach ($list as $d) if (mb_strtolower($d['name']) === $n) return $d;
            foreach ($list as $d) if (str_contains(mb_strtolower($d['name']), $n) || str_contains($n, mb_strtolower($d['name']))) return $d;
            return null;
        };
        if ($name !== '') return $find($name) ?? throw new SpotifyError("No Spotify device called \"$name\". Available: " . implode(', ', array_column($list, 'name')) . '.', 'other');
        foreach ($list as $d) if (!empty($d['is_active'])) return $d;
        if ($d = $find($this->app->cfg->spotifyDevice)) return $d;
        foreach ($list as $d) if (strcasecmp((string)($d['type'] ?? ''), 'Smartphone') === 0) return $d;
        return $list[0];
    }

    private static function artists(array $item): string { return implode(', ', array_column($item['artists'] ?? [], 'name')); }

    /** Find what to play. $type: track | artist | album | playlist. Your own playlists come first. */
    public function find(string $query, string $type = 'track'): ?array
    {
        if (preg_match('#^spotify:(track|album|artist|playlist):[A-Za-z0-9]+$#', $query, $m)) return ['uri' => $query, 'type' => $m[1], 'label' => $query];
        $type = in_array($type, ['track', 'artist', 'album', 'playlist'], true) ? $type : 'track';
        if ($type === 'playlist') {
            foreach ($this->api('GET', '/me/playlists', null, ['limit' => 50])['items'] ?? [] as $p) {
                if (is_array($p) && mb_strtolower(trim($p['name'])) === mb_strtolower(trim($query))) return ['uri' => $p['uri'], 'type' => 'playlist', 'label' => "your playlist \"{$p['name']}\"", 'id' => $p['id']];
            }
        }
        $r = $this->api('GET', '/search', null, ['q' => $query, 'type' => $type, 'limit' => 5, 'market' => 'from_token']);
        $items = array_values(array_filter($r[$type . 's']['items'] ?? [], 'is_array')); // Spotify sometimes puts nulls in the list
        if (!$items) return null;
        $i = $items[0];
        $label = match ($type) {
            'track' => "\"{$i['name']}\" by " . self::artists($i),
            'album' => "the album \"{$i['name']}\" by " . self::artists($i),
            'artist' => $i['name'],
            'playlist' => "the playlist \"{$i['name']}\"",
        };
        return ['uri' => $i['uri'], 'type' => $type, 'label' => $label, 'id' => $i['id'] ?? ''];
    }

    /** A button that opens Spotify on the phone at this item (when it can't be started from here). */
    public static function openLink(?string $uri, string $query = ''): string
    {
        $href = $uri ?: 'spotify:search:' . rawurlencode($query);
        return "[▶ Open in Spotify]($href)";
    }

    /** Play something (or resume when $query is empty). $queue: add to the queue instead. */
    public function play(string $query, string $type = 'track', string $device = '', bool $queue = false): string
    {
        $item = $query !== '' ? $this->find($query, $type) : null;
        if ($query !== '' && !$item) return "Nothing found on Spotify for \"$query\".";
        try {
            $d = $this->pickDevice($device);
            if (!$d) throw new SpotifyError('No Spotify device is available.', 'device');
            if ($queue) {
                if (!$item || $item['type'] !== 'track') return 'Only songs can be added to the queue.';
                $this->api('POST', '/me/player/queue', null, ['uri' => $item['uri'], 'device_id' => $d['id']]);
                return "Added {$item['label']} to the queue on {$d['name']}.";
            }
            $body = !$item ? null : ($item['type'] === 'track' ? ['uris' => [$item['uri']]] : ['context_uri' => $item['uri']]);
            $this->api('PUT', '/me/player/play', $body, ['device_id' => $d['id']]);
            return ($item ? "Playing {$item['label']}" : 'Resumed') . " on {$d['name']}.";
        } catch (SpotifyError $e) {
            if ($e->kind !== 'device') throw $e;
            return "No Spotify device is available right now (Spotify must be open on the phone or a speaker). Give the user this button, which opens Spotify"
                . ($item ? ' on it' : '') . ': ' . self::openLink($item['uri'] ?? null, $query) . ' — then ask again and it will play from here.';
        }
    }

    /** pause | resume | next | previous | volume (0-100, or +10/-10) | shuffle (on/off) | repeat (track/context/off) | transfer (device name) */
    public function control(string $action, string $value = ''): string
    {
        $state = $this->api('GET', '/me/player');
        $dev = $state['device'] ?? null;
        if (!$dev && $action !== 'transfer') return 'Nothing is playing on Spotify right now.';
        $q = $dev ? ['device_id' => $dev['id']] : [];
        switch ($action) {
            case 'pause': $this->api('PUT', '/me/player/pause', null, $q); return 'Paused.';
            case 'resume': $this->api('PUT', '/me/player/play', null, $q); return 'Resumed on ' . $dev['name'] . '.';
            case 'next': $this->api('POST', '/me/player/next', null, $q); return 'Next song.';
            case 'previous': $this->api('POST', '/me/player/previous', null, $q); return 'Previous song.';
            case 'volume':
                $v = trim($value);
                $level = preg_match('/^[+-]\d+$/', $v) ? (int)($dev['volume_percent'] ?? 50) + (int)$v : (int)$v;
                $level = max(0, min(100, $level));
                $this->api('PUT', '/me/player/volume', null, $q + ['volume_percent' => $level]);
                return "Volume $level%.";
            case 'shuffle':
                $on = !in_array(mb_strtolower(trim($value)), ['off', 'false', '0', 'no', 'non'], true);
                $this->api('PUT', '/me/player/shuffle', null, $q + ['state' => $on ? 'true' : 'false']);
                return 'Shuffle ' . ($on ? 'on.' : 'off.');
            case 'repeat':
                $mode = in_array($value, ['track', 'context', 'off'], true) ? $value : 'context';
                $this->api('PUT', '/me/player/repeat', null, $q + ['state' => $mode]);
                return "Repeat: $mode.";
            case 'transfer':
                $to = $this->pickDevice($value) ?? throw new SpotifyError('No Spotify device is available.', 'device');
                $this->api('PUT', '/me/player', ['device_ids' => [$to['id']], 'play' => true]);
                return "Now playing on {$to['name']}.";
        }
        throw new \InvalidArgumentException("unknown action $action");
    }

    /** What is playing, and where it could play. */
    public function now(): string
    {
        $s = $this->api('GET', '/me/player');
        $out = [];
        if (!empty($s['item'])) {
            $i = $s['item'];
            $pos = fn(int $ms) => sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
            $out[] = (!empty($s['is_playing']) ? 'Playing' : 'Paused') . ": \"{$i['name']}\"" . (isset($i['artists']) ? ' by ' . self::artists($i) : '') . (isset($i['album']['name']) ? " ({$i['album']['name']})" : '')
                . ', ' . $pos((int)($s['progress_ms'] ?? 0)) . ' / ' . $pos((int)($i['duration_ms'] ?? 0)) . " on {$s['device']['name']}, volume " . ($s['device']['volume_percent'] ?? '?') . '%'
                . (!empty($s['shuffle_state']) ? ', shuffle' : '') . (($s['repeat_state'] ?? 'off') !== 'off' ? ", repeat {$s['repeat_state']}" : '') . ". uri {$i['uri']}";
        } else {
            $out[] = 'Nothing is playing.';
        }
        $devs = $this->devices();
        $out[] = $devs ? 'Devices: ' . implode(', ', array_map(fn($d) => $d['name'] . " ({$d['type']}" . (!empty($d['is_active']) ? ', active' : '') . ')', $devs))
            : 'No device available (Spotify must be open on the phone or a speaker).';
        return implode("\n", $out);
    }

    /** Save a song (the one playing, or found by $query) to your liked songs or to one of your playlists. */
    public function save(string $query = '', string $playlist = ''): string
    {
        if ($query !== '') { $item = $this->find($query, 'track') ?? throw new SpotifyError("Nothing found on Spotify for \"$query\".", 'other'); }
        else {
            $s = $this->api('GET', '/me/player');
            if (empty($s['item']['uri'])) return 'Nothing is playing: say which song to save.';
            $item = ['uri' => $s['item']['uri'], 'label' => "\"{$s['item']['name']}\" by " . self::artists($s['item'])];
        }
        if ($playlist === '') {
            try { $this->api('PUT', '/me/library', null, ['uris' => $item['uri']]); }
            catch (SpotifyError $e) { // older API
                if (!in_array($e->getCode(), [404, 405, 410], true)) throw $e;
                $this->api('PUT', '/me/tracks', null, ['ids' => substr($item['uri'], strrpos($item['uri'], ':') + 1)]);
            }
            return "Saved {$item['label']} to your liked songs.";
        }
        $mine = array_values(array_filter($this->api('GET', '/me/playlists', null, ['limit' => 50])['items'] ?? [], 'is_array'));
        $p = null;
        foreach ($mine as $x) if (mb_strtolower(trim($x['name'])) === mb_strtolower(trim($playlist))) { $p = $x; break; }
        if (!$p) foreach ($mine as $x) if (str_contains(mb_strtolower($x['name']), mb_strtolower(trim($playlist)))) { $p = $x; break; }
        if (!$p) return "No playlist of yours is called \"$playlist\". Yours: " . implode(', ', array_slice(array_column($mine, 'name'), 0, 30)) . '.';
        $this->api('POST', '/playlists/' . rawurlencode($p['id']) . '/items', ['uris' => [$item['uri']]]);
        return "Added {$item['label']} to \"{$p['name']}\".";
    }

    public function register(Tools $t): void
    {
        if (!$this->connected()) return;
        $t->register('spotify_play',
            "Play music on the user's Spotify (their phone or a speaker): a song, an artist, an album or one of their playlists. Choose type: 'artist' for \"play Daft Punk\", 'playlist' for \"my running playlist\", 'album', else 'track'. Empty query resumes. queue=true adds a song after the current one. device: a device name, only when the user names one.",
            ['query' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['track', 'artist', 'album', 'playlist']], 'device' => ['type' => 'string'], 'queue' => ['type' => 'boolean']], [],
            fn(array $a) => $this->play(trim((string)($a['query'] ?? '')), (string)($a['type'] ?? 'track'), (string)($a['device'] ?? ''), !empty($a['queue'])));
        $t->register('spotify_control', "Control what is playing on Spotify. volume: 0-100 or +10/-10. shuffle: on/off. repeat: track/context/off. transfer: move the music to another device (value = its name).",
            ['action' => ['type' => 'string', 'enum' => ['pause', 'resume', 'next', 'previous', 'volume', 'shuffle', 'repeat', 'transfer']], 'value' => ['type' => 'string']], ['action'],
            fn(array $a) => $this->control($a['action'], (string)($a['value'] ?? '')));
        $t->register('spotify_now', 'What is playing on Spotify right now, and the devices it can play on.', [], [], fn() => $this->now());
        $t->register('spotify_save', "Save a song to the user's liked songs, or add it to one of their playlists (by name). Without query: the song playing now.",
            ['query' => ['type' => 'string'], 'playlist' => ['type' => 'string']], [],
            fn(array $a) => $this->save(trim((string)($a['query'] ?? '')), trim((string)($a['playlist'] ?? ''))));
    }
}
