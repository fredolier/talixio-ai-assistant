<?php
// Fake Microsoft identity platform + Graph calendar for tests (times in UTC, like Graph with Prefer: outlook.timezone="UTC").
date_default_timezone_set('UTC');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = sys_get_temp_dir() . '/fake-microsoft-' . ($_SERVER['SERVER_PORT'] ?? '') . '.json';
$state = json_decode((string)@file_get_contents($log), true) ?: ['inserted' => []];
header('Content-Type: application/json');
if ($path === '/common/oauth2/v2.0/token') {
    parse_str((string)file_get_contents('php://input'), $p);
    if (($p['client_secret'] ?? '') !== 'msec') { http_response_code(401); echo '{"error":"invalid_client"}'; return; }
    if ($p['grant_type'] === 'authorization_code' && $p['code'] === 'good-code') {
        $idt = 'x.' . rtrim(strtr(base64_encode(json_encode(['preferred_username' => 'fred@outlook.com'])), '+/', '-_'), '=') . '.y';
        echo json_encode(['access_token' => 'mt-1', 'refresh_token' => 'mr-1', 'expires_in' => 3600, 'id_token' => $idt]); return;
    }
    if ($p['grant_type'] === 'refresh_token' && $p['refresh_token'] === 'mr-1') { echo json_encode(['access_token' => 'mt-2', 'refresh_token' => 'mr-2', 'expires_in' => 3600]); return; }
    http_response_code(400); echo '{"error":"invalid_grant"}'; return;
}
if (!in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer mt-1', 'Bearer mt-2'], true)) { http_response_code(401); echo '{"error":{"code":"InvalidAuthenticationToken","message":"bad token"}}'; return; }
if (preg_match('#^/v1.0/me/events/([^/]+)/instances$#', $path, $m)) { // weekly occurrences of the inserted series in the range asked, in UTC
    $ev = $state['inserted'][(int)substr($m[1], 1) - 1] ?? [];
    $t = (new DateTime($ev['start']['dateTime'] ?? 'now', new DateTimeZone($ev['start']['timeZone'] ?? 'UTC')))->getTimestamp();
    $out = [];
    for ($i = 0; $t <= strtotime($_GET['endDateTime'] ?? 'now') && $i < 100; $i++, $t = strtotime('+1 week', $t)) {
        if ($t >= strtotime($_GET['startDateTime'] ?? 'now')) $out[] = ['id' => "$m[1]-" . gmdate('Ymd', $t), 'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s.0000000', $t), 'timeZone' => 'UTC']];
    }
    echo json_encode(['value' => $out]); return;
}
if (preg_match('#^/v1.0/me/events/([^/]+)$#', $path, $m)) { // task events: update, delete
    $state[$_SERVER['REQUEST_METHOD'] === 'DELETE' ? 'deleted' : 'updated'][] = ['id' => $m[1], 'body' => json_decode((string)file_get_contents('php://input'), true)];
    file_put_contents($log, json_encode($state));
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { http_response_code(204); return; }
    if ($m[1] === 'gone') { http_response_code(404); echo '{"error":{"code":"ErrorItemNotFound","message":"The specified object was not found in the store."}}'; return; }
    echo json_encode(['id' => $m[1]]); return;
}
if ($path === '/v1.0/me/events' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $state['inserted'][] = json_decode((string)file_get_contents('php://input'), true);
    file_put_contents($log, json_encode($state)); echo json_encode(['id' => 'm' . count($state['inserted'])]); return;
}
if ($path === '/v1.0/me/calendarView') {
    $state['views'][] = ['query' => $_GET, 'prefer' => $_SERVER['HTTP_PREFER'] ?? ''];
    file_put_contents($log, json_encode($state));
    $t = strtotime('tomorrow 10:00'); $u = fn(int $x) => gmdate('Y-m-d\TH:i:s.0000000', $x);
    if (($_GET['page'] ?? '') === '2') { echo json_encode(['value' => [['id' => 'p2', 'iCalUId' => 'p2@ms', 'subject' => 'Board meeting', 'isAllDay' => false, 'start' => ['dateTime' => $u($t + 7200), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $u($t + 9000), 'timeZone' => 'UTC']]]]); return; }
    echo json_encode(['value' => [
        ['id' => 'o1', 'iCalUId' => 'o1@ms', 'subject' => 'Lunch with Anna', 'location' => ['displayName' => 'Paris'], 'isAllDay' => false, 'start' => ['dateTime' => $u($t), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $u($t + 3600), 'timeZone' => 'UTC']],
        ['id' => 'o2', 'subject' => 'Holiday', 'isAllDay' => true, 'start' => ['dateTime' => date('Y-m-d', $t) . 'T00:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => date('Y-m-d', $t + 86400) . 'T00:00:00.0000000', 'timeZone' => 'UTC']],
        ['id' => 'o3', 'subject' => 'Called off', 'isCancelled' => true, 'start' => ['dateTime' => $u($t), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $u($t + 60), 'timeZone' => 'UTC']],
        ['id' => 'o4', 'subject' => 'MAI-9 · a task', 'singleValueExtendedProperties' => [['id' => 'x', 'value' => '9']], 'isAllDay' => true, 'start' => ['dateTime' => date('Y-m-d', $t) . 'T00:00:00', 'timeZone' => 'UTC'], 'end' => ['dateTime' => date('Y-m-d', $t + 86400) . 'T00:00:00', 'timeZone' => 'UTC']],
    ], '@odata.nextLink' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/v1.0/me/calendarView?page=2']); return;
}
http_response_code(404); echo '{}';
