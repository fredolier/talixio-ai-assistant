<?php
// Fake Google OAuth + Calendar API for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = sys_get_temp_dir() . '/fake-google-' . ($_SERVER['SERVER_PORT'] ?? '') . '.json';
$state = json_decode((string)@file_get_contents($log), true) ?: ['inserted' => []];
header('Content-Type: application/json');
if ($path === '/token') {
    parse_str((string)file_get_contents('php://input'), $p);
    if (($p['client_secret'] ?? '') !== 'sec') { http_response_code(401); echo '{"error":"invalid_client"}'; return; }
    if ($p['grant_type'] === 'authorization_code' && $p['code'] === 'good-code') {
        $idt = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => 'fred@gmail.com'])), '+/', '-_'), '=') . '.y';
        echo json_encode(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'id_token' => $idt]); return;
    }
    if ($p['grant_type'] === 'refresh_token' && $p['refresh_token'] === 'rt-1') { echo json_encode(['access_token' => 'at-2', 'expires_in' => 3600]); return; }
    http_response_code(400); echo '{"error":"invalid_grant"}'; return;
}
if ($path === '/revoke') { echo '{}'; return; }
if (!in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer at-1', 'Bearer at-2'], true)) { http_response_code(401); echo '{"error":{"message":"bad token"}}'; return; }
if ($path === '/calendar/v3/users/me/calendarList') {
    echo json_encode(['items' => [['id' => 'fred@gmail.com', 'summary' => 'fred@gmail.com', 'primary' => true, 'selected' => true],
        ['id' => 'family#group', 'summary' => 'Family', 'selected' => true], ['id' => 'hidden', 'summary' => 'Hidden']]]); return;
}
if (preg_match('#^/calendar/v3/calendars/([^/]+)/events$#', $path, $m)) {
    $cal = rawurldecode($m[1]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state['inserted'][] = json_decode((string)file_get_contents('php://input'), true);
        file_put_contents($log, json_encode($state)); echo '{"id":"new1"}'; return;
    }
    $t = strtotime('tomorrow 10:00');
    $items = $cal === 'family#group'
        ? [['id' => 'f1', 'iCalUID' => 'f1@google', 'summary' => 'School play', 'start' => ['date' => date('Y-m-d', $t)], 'end' => ['date' => date('Y-m-d', $t + 86400)]]]
        : [['id' => 'e1', 'iCalUID' => 'e1@google', 'summary' => 'Dentist', 'location' => 'Lyon', 'start' => ['dateTime' => date('c', $t)], 'end' => ['dateTime' => date('c', $t + 3600)]],
           ['id' => 'e2', 'summary' => 'Cancelled thing', 'status' => 'cancelled', 'start' => ['dateTime' => date('c', $t)], 'end' => ['dateTime' => date('c', $t + 60)]]];
    echo json_encode(['items' => $items]); return;
}
http_response_code(404); echo '{}';
