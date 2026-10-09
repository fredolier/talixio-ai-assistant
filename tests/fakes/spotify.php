<?php
// Fake Spotify accounts + Web API for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$log = sys_get_temp_dir() . '/fake-spotify-' . ($_SERVER['SERVER_PORT'] ?? '') . '.json';
$state = json_decode((string)@file_get_contents($log), true) ?: ['calls' => [], 'devices' => true];
$m = $_SERVER['REQUEST_METHOD'];
$body = (string)file_get_contents('php://input');
header('Content-Type: application/json');
if ($path === '/api/token') {
    parse_str($body, $p);
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('scid:ssec')) { http_response_code(400); echo '{"error":"invalid_client"}'; return; }
    if ($p['grant_type'] === 'authorization_code' && $p['code'] === 'good-code') { echo json_encode(['access_token' => 'sat-1', 'refresh_token' => 'srt-1', 'expires_in' => 3600]); return; }
    if ($p['grant_type'] === 'refresh_token' && $p['refresh_token'] === 'srt-1') { echo json_encode(['access_token' => 'sat-2', 'refresh_token' => 'srt-2', 'expires_in' => 3600]); return; }
    if ($p['grant_type'] === 'refresh_token' && $p['refresh_token'] === 'srt-2') { echo json_encode(['access_token' => 'sat-3', 'expires_in' => 3600]); return; }
    http_response_code(400); echo '{"error":"invalid_grant"}'; return;
}
if (!in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer sat-1', 'Bearer sat-2', 'Bearer sat-3'], true)) { http_response_code(401); echo '{"error":{"status":401,"message":"bad token"}}'; return; }
$state['calls'][] = [$m, $path, $q, json_decode($body, true)];
file_put_contents($log, json_encode($state));
$track = ['uri' => 'spotify:track:T1', 'id' => 'T1', 'name' => 'Get Lucky', 'artists' => [['name' => 'Daft Punk'], ['name' => 'Pharrell Williams']], 'album' => ['name' => 'Random Access Memories'], 'duration_ms' => 369000];
$devices = $state['devices'] ? [['id' => 'D1', 'name' => 'Living room', 'type' => 'Speaker', 'is_active' => false, 'volume_percent' => 30],
    ['id' => 'D2', 'name' => 'Galaxy S25 Ultra', 'type' => 'Smartphone', 'is_active' => false, 'volume_percent' => 60]] : [];
switch ("$m $path") {
    case 'GET /v1/me': echo json_encode(['id' => 'fred', 'display_name' => 'Fred', 'product' => 'premium']); return;
    case 'GET /v1/me/player/devices': echo json_encode(['devices' => $devices]); return;
    case 'GET /v1/me/player':
        if (!$state['devices']) { http_response_code(204); return; }
        echo json_encode(['is_playing' => true, 'progress_ms' => 65000, 'item' => $track, 'device' => ['id' => 'D2', 'name' => 'Galaxy S25 Ultra', 'volume_percent' => 60], 'shuffle_state' => false, 'repeat_state' => 'off']); return;
    case 'GET /v1/search':
        $t = $q['type'];
        $items = match ($t) { 'track' => [$track], 'artist' => [null, ['uri' => 'spotify:artist:A1', 'id' => 'A1', 'name' => 'Daft Punk']], default => [] };
        echo json_encode([$t . 's' => ['items' => $items]]); return;
    case 'GET /v1/me/playlists': echo json_encode(['items' => [['id' => 'P1', 'uri' => 'spotify:playlist:P1', 'name' => 'Running'], null]]); return;
    case 'PUT /v1/me/player/play':
        if (!in_array($q['device_id'] ?? '', ['D1', 'D2'], true)) { http_response_code(404); echo '{"error":{"status":404,"message":"Device not found","reason":"NO_ACTIVE_DEVICE"}}'; return; }
        http_response_code(204); return;
    case 'PUT /v1/me/player/pause': case 'POST /v1/me/player/next': case 'PUT /v1/me/player/volume': case 'POST /v1/me/player/queue': case 'PUT /v1/me/player':
    case 'PUT /v1/me/library': case 'POST /v1/playlists/P1/items':
        http_response_code(204); return;
}
http_response_code(404); echo '{"error":{"status":404,"message":"Service not found"}}';
