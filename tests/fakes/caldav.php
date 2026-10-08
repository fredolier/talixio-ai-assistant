<?php
// Fake CalDAV collection for tests: REPORT returns stored events, PUT stores one.
$dir = getenv('TALIXIO_FAKE_CALDAV') ?: sys_get_temp_dir();
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'fred' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'pw') { http_response_code(401); return; }
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '*') { http_response_code(400); return; }
    file_put_contents("$dir/" . basename($_SERVER['REQUEST_URI']), file_get_contents('php://input'));
    http_response_code(201); return;
}
if ($_SERVER['REQUEST_METHOD'] === 'REPORT') {
    file_put_contents("$dir/report.xml", file_get_contents('php://input'));
    header('Content-Type: application/xml');
    echo '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">';
    foreach (glob("$dir/*.ics") as $f) echo '<d:response><d:href>/cal/' . basename($f) . '</d:href><d:propstat><d:prop><cal:calendar-data>' . htmlspecialchars(file_get_contents($f), ENT_XML1) . '</cal:calendar-data></d:prop></d:propstat></d:response>';
    echo '</d:multistatus>';
}
