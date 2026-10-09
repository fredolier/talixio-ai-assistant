<?php
// Fake remote MCP server protected by OAuth (MCP authorization spec), for tests, under `php -S`:
// protected-resource metadata, authorization-server metadata, dynamic registration, authorize (approves
// at once), token (checks PKCE and the resource), refresh; /mcp answers only with a valid token.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = 'http://' . $_SERVER['HTTP_HOST'];
$file = sys_get_temp_dir() . '/fake-mcp-oauth-' . $_SERVER['SERVER_PORT'] . '.json';
$st = json_decode((string)@file_get_contents($file), true) ?: ['clients' => [], 'codes' => [], 'tokens' => [], 'log' => []];
$save = function () use (&$st, $file) { file_put_contents($file, json_encode($st)); };
$json = function ($d, int $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); };
$b64 = fn(string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

if ($path === '/.well-known/oauth-protected-resource/mcp') { $json(['resource' => "$base/mcp", 'authorization_servers' => ["$base/auth"], 'scopes_supported' => ['notes:read', 'notes:write']]); return; }
if ($path === '/.well-known/oauth-authorization-server/auth') {
    $json(['issuer' => "$base/auth", 'authorization_endpoint' => "$base/auth/authorize", 'token_endpoint' => "$base/auth/token", 'registration_endpoint' => "$base/auth/register",
        'code_challenge_methods_supported' => ['S256'], 'response_types_supported' => ['code']]);
    return;
}
if ($path === '/auth/register') {
    $r = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (empty($r['redirect_uris'])) { $json(['error' => 'invalid_redirect_uri'], 400); return; }
    $id = 'client-' . (count($st['clients']) + 1);
    $st['clients'][$id] = $r['redirect_uris']; $save();
    $json(['client_id' => $id, 'redirect_uris' => $r['redirect_uris']], 201);
    return;
}
if ($path === '/auth/authorize') {
    $q = $_GET;
    if (!isset($st['clients'][$q['client_id'] ?? '']) || !in_array($q['redirect_uri'] ?? '', $st['clients'][$q['client_id']], true)) { http_response_code(400); echo 'bad client or redirect'; return; }
    if (($q['code_challenge_method'] ?? '') !== 'S256' || empty($q['code_challenge']) || ($q['resource'] ?? '') !== "$base/mcp") { http_response_code(400); echo 'PKCE and resource required'; return; }
    $code = 'code-' . bin2hex(random_bytes(4));
    $st['codes'][$code] = ['client' => $q['client_id'], 'challenge' => $q['code_challenge'], 'redirect' => $q['redirect_uri'], 'scope' => $q['scope'] ?? ''];
    $st['log'][] = 'authorize scope=' . ($q['scope'] ?? '');
    $save();
    header('Location: ' . $q['redirect_uri'] . '?' . http_build_query(['code' => $code, 'state' => $q['state']]), true, 302);
    return;
}
if ($path === '/auth/token') {
    parse_str((string)file_get_contents('php://input'), $p);
    if (($p['resource'] ?? '') !== "$base/mcp") { $json(['error' => 'invalid_target'], 400); return; }
    if ($p['grant_type'] === 'authorization_code') {
        $c = $st['codes'][$p['code'] ?? ''] ?? null;
        if (!$c || $c['client'] !== ($p['client_id'] ?? '') || $c['redirect'] !== ($p['redirect_uri'] ?? '') || $b64(hash('sha256', (string)($p['code_verifier'] ?? ''), true)) !== $c['challenge']) { $json(['error' => 'invalid_grant'], 400); return; }
        unset($st['codes'][$p['code']]);
        $st['tokens']['at-1'] = time() + 3600; $save();
        $json(['access_token' => 'at-1', 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'rt-1']);
        return;
    }
    if ($p['grant_type'] === 'refresh_token' && ($p['refresh_token'] ?? '') === 'rt-1') {
        $st['tokens']['at-2'] = time() + 3600; $st['log'][] = 'refreshed'; $save();
        $json(['access_token' => 'at-2', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        return;
    }
    $json(['error' => 'invalid_grant'], 400);
    return;
}
if ($path === '/revoke-all') { $st['tokens'] = []; $save(); $json(['ok' => true]); return; } // test helper: the server forgets its tokens
if ($path === '/mcp') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tok = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if (!isset($st['tokens'][$tok])) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer resource_metadata="' . $base . '/.well-known/oauth-protected-resource/mcp"');
        return;
    }
    $m = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!isset($m['id'])) { http_response_code(202); return; }
    $r = match ($m['method']) {
        'initialize' => ['protocolVersion' => $m['params']['protocolVersion'], 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'notes', 'version' => '1']],
        'tools/list' => ['tools' => [['name' => 'search_notes', 'description' => 'Search notes', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]]]],
        'tools/call' => ['content' => [['type' => 'text', 'text' => "notes about {$m['params']['arguments']['q']} (token $tok)"]]],
        default => null,
    };
    $json(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $r]);
    return;
}
http_response_code(404);
