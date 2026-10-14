<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Sign-in for remote MCP servers that use OAuth (Notion, Slack, Atlassian, Linear...), following the
 * MCP authorization spec: the server answers 401 and points to its protected-resource metadata, which
 * names its authorization server; Talixio registers itself there (dynamic client registration, or a
 * client_id given in mcp.json), sends you to sign in (authorization code + PKCE, with the server as
 * "resource"), keeps the tokens in data/mcp-oauth.json (private) and renews them.
 */
final class McpOAuth
{
    public function __construct(private App $app) {}

    private function file(): string { return $this->app->cfg->dataDir . '/mcp-oauth.json'; }
    private function all(): array { return json_decode((string)@file_get_contents($this->file()), true) ?: []; }

    private function put(string $server, ?array $entry): void
    {
        $all = $this->all();
        if ($entry === null) unset($all[$server]); else $all[$server] = $entry;
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    public function get(string $server): array { return $this->all()[$server] ?? []; }
    public function signedIn(string $server): bool { return !empty($this->get($server)['tokens']['access_token']); }
    public function account(string $server): string { return (string)($this->get($server)['tokens']['signed_in_at'] ?? ''); }

    /** Where the authorization server sends the browser back (must be the same at registration and sign-in). */
    public static function redirectUri(string $publicUrl = ''): string { return preg_replace('#/api/google/callback$#', '/api/mcp/oauth/callback', Google::redirectUri($publicUrl)); }

    private static function getJson(string $url, string $serverUrl): ?array
    {
        try {
            self::mayReach($url, $serverUrl);
            [$code, $body] = Http::request('GET', $url, ['Accept: application/json', 'MCP-Protocol-Version: ' . Mcp::PROTOCOL], null, 20);
        } catch (\Throwable) { return null; }
        $d = json_decode($body, true);
        return $code === 200 && is_array($d) ? $d : null;
    }

    /**
     * The addresses a server hands out (its metadata, sign-in and token endpoints) stay on the connector's own host
     * or on a public one: a remote server cannot point Talixio at your router or another service on your network.
     */
    public static function mayReach(string $url, string $serverUrl): void
    {
        $p = parse_url($url);
        if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) throw new \RuntimeException('This server gave an address that is not http(s).');
        if (strcasecmp($p['host'], (string)parse_url($serverUrl, PHP_URL_HOST)) === 0) return;
        Http::publicIp($p['host']); // throws for private and local addresses
    }

    /** "https://host/a/b" → ["https://host", "/a/b"] */
    private static function split(string $url): array
    {
        $p = parse_url($url);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        return [$origin, rtrim($p['path'] ?? '', '/')];
    }

    /** Find the server's authorization server and its endpoints. $hint: the resource_metadata URL from a 401, if any. */
    public function discover(string $serverUrl, ?string $hint = null): array
    {
        [$origin, $path] = self::split($serverUrl);
        $prm = null;
        foreach (array_filter([$hint, "$origin/.well-known/oauth-protected-resource$path", "$origin/.well-known/oauth-protected-resource"]) as $u) {
            if ($prm = self::getJson($u, $serverUrl)) break;
        }
        $issuer = (string)($prm['authorization_servers'][0] ?? $origin); // older servers: the MCP server's origin is the authorization server
        [$iOrigin, $iPath] = self::split($issuer);
        $as = null;
        foreach (["$iOrigin/.well-known/oauth-authorization-server$iPath", "$iOrigin/.well-known/openid-configuration$iPath", "$issuer/.well-known/openid-configuration"] as $u) {
            if ($as = self::getJson($u, $serverUrl)) break;
        }
        $as ??= ['authorization_endpoint' => "$iOrigin/authorize", 'token_endpoint' => "$iOrigin/token", 'registration_endpoint' => "$iOrigin/register"];
        if (empty($as['authorization_endpoint']) || empty($as['token_endpoint'])) throw new \RuntimeException('This server does not say where to sign in.');
        foreach (['token_endpoint', 'registration_endpoint'] as $k) if (!empty($as[$k])) self::mayReach((string)$as[$k], $serverUrl); // Talixio posts to these itself
        if (isset($as['code_challenge_methods_supported']) && !in_array('S256', (array)$as['code_challenge_methods_supported'], true)) throw new \RuntimeException('This server does not support secure sign-in (PKCE S256).');
        return ['resource' => (string)($prm['resource'] ?? $serverUrl), 'scopes' => (array)($prm['scopes_supported'] ?? $as['scopes_supported'] ?? []),
            'authorization_endpoint' => (string)$as['authorization_endpoint'], 'token_endpoint' => (string)$as['token_endpoint'],
            'registration_endpoint' => (string)($as['registration_endpoint'] ?? '')];
    }

    /** Step 1: the address of the sign-in page. $cfg: the server's entry in mcp.json (url, oauth: {client_id, client_secret, scope}). */
    public function start(string $server, array $cfg, ?string $hint = null): string
    {
        if (empty($cfg['url'])) throw new \InvalidArgumentException('Only remote connectors (url) sign in.');
        $redirect = self::redirectUri($this->app->cfg->publicUrl);
        $e = $this->get($server);
        $meta = $this->discover((string)$cfg['url'], $hint);
        $o = (array)($cfg['oauth'] ?? []);
        $client = $e['client'] ?? null;
        if (!empty($o['client_id'])) $client = ['client_id' => (string)$o['client_id'], 'client_secret' => (string)($o['client_secret'] ?? ''), 'redirect_uri' => $redirect];
        if (!$client || ($client['redirect_uri'] ?? '') !== $redirect || ($client['registration_endpoint'] ?? '') !== $meta['registration_endpoint']) {
            if (!empty($o['client_id'])) { /* given in mcp.json */ }
            elseif ($meta['registration_endpoint'] === '') throw new \RuntimeException('This server needs a client id: create an OAuth app there with the redirect URI ' . $redirect . ' and add "oauth": {"client_id": "…", "client_secret": "…"} to the connector.');
            else {
                [$code, $body] = Http::request('POST', $meta['registration_endpoint'], ['Content-Type: application/json', 'Accept: application/json'], json_encode([
                    'client_name' => $this->app->cfg->name . ' (Talixio)', 'redirect_uris' => [$redirect], 'grant_types' => ['authorization_code', 'refresh_token'],
                    'response_types' => ['code'], 'token_endpoint_auth_method' => 'none', 'application_type' => 'web'], JSON_UNESCAPED_SLASHES), 20);
                $d = json_decode($body, true) ?: [];
                if ($code >= 400 || empty($d['client_id'])) throw new \RuntimeException('Registration refused by the server: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code"));
                $client = ['client_id' => (string)$d['client_id'], 'client_secret' => (string)($d['client_secret'] ?? ''), 'redirect_uri' => $redirect, 'registration_endpoint' => $meta['registration_endpoint']];
            }
        }
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        $scope = (string)($o['scope'] ?? implode(' ', $meta['scopes']));
        $this->put($server, ['client' => $client, 'meta' => $meta, 'tokens' => $e['tokens'] ?? null,
            'pending' => ['state' => $state, 'verifier' => $verifier, 'ts' => time()]]);
        return $meta['authorization_endpoint'] . (str_contains($meta['authorization_endpoint'], '?') ? '&' : '?') . http_build_query(array_filter([
            'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $redirect, 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'scope' => $scope, 'resource' => $meta['resource']]));
    }

    /** Step 2, back from the sign-in page: check the state, trade the code for tokens. Returns the server's name. */
    public function finish(string $code, string $state): string
    {
        foreach ($this->all() as $server => $e) {
            $p = $e['pending'] ?? null;
            if (!$p || !hash_equals((string)$p['state'], $state)) continue;
            if ($p['ts'] < time() - 900) throw new \InvalidArgumentException('This sign-in link expired: press "Sign in" again.');
            $t = $this->token($e, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $e['client']['redirect_uri'], 'code_verifier' => $p['verifier']]);
            $e['tokens'] = $t; unset($e['pending']);
            $this->put($server, $e);
            return $server;
        }
        throw new \InvalidArgumentException('Unknown or already used sign-in: press "Sign in" again.');
    }

    private function token(array $e, array $params): array
    {
        $c = $e['client'];
        $params += ['client_id' => $c['client_id'], 'resource' => $e['meta']['resource']];
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        if (($c['client_secret'] ?? '') !== '') $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']));
        [$code, $body] = Http::request('POST', $e['meta']['token_endpoint'], $headers, http_build_query($params), 30);
        $d = json_decode($body, true) ?: [];
        if ($code >= 400 || empty($d['access_token'])) throw new \RuntimeException('Sign-in failed: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code"));
        return ['access_token' => (string)$d['access_token'], 'refresh_token' => (string)($d['refresh_token'] ?? ($params['refresh_token'] ?? '')),
            'expires' => isset($d['expires_in']) ? time() + (int)$d['expires_in'] - 60 : null, 'signed_in_at' => date('Y-m-d H:i')];
    }

    /** A valid access token, renewed when it has expired (null: sign in needed). $force: renew even if it looks valid (after a 401). */
    public function accessToken(string $server, bool $force = false): ?string
    {
        $e = $this->get($server);
        $t = $e['tokens'] ?? null;
        if (!$t || empty($t['access_token'])) return null;
        if (!$force && ($t['expires'] === null || $t['expires'] > time())) return $t['access_token'];
        if (empty($t['refresh_token'])) { $e['tokens'] = null; $this->put($server, $e); return null; }
        try {
            $e['tokens'] = $this->token($e, ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
        } catch (\Throwable $ex) {
            Log::warn("mcp $server: token renewal failed: {$ex->getMessage()}");
            $e['tokens'] = null; $this->put($server, $e); return null;
        }
        $this->put($server, $e);
        return $e['tokens']['access_token'];
    }

    public function forget(string $server): void { $this->put($server, null); }
}
