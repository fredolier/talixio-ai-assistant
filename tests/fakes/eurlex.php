<?php
// Fake EUR-Lex: CELLAR SPARQL + content-by-CELEX for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string)file_get_contents('php://input');

if ($path === '/sparql') {
    parse_str($body, $p);
    $q = strtolower($p['query'] ?? '');
    header('Content-Type: application/sparql-results+json');
    $bindings = str_contains($q, 'protection des données') || str_contains($q, 'data protection') ? [
        ['celex' => ['value' => '32016R0679'], 'title' => ['value' => 'General Data Protection Regulation (GDPR)'], 'd' => ['value' => '2016-04-27']],
    ] : [];
    echo json_encode(['results' => ['bindings' => $bindings]]); return;
}
if (preg_match('#^/resource/celex/(.+)$#', $path, $mm)) {
    $celex = rawurldecode($mm[1]);
    if ($celex !== '32016R0679') { http_response_code(404); echo 'Not found'; return; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><h1>Regulation (EU) 2016/679 (GDPR)</h1><p>Article&nbsp;1<br/>This Regulation lays down rules&hellip;</p><script>x()</script></body></html>'; return;
}
http_response_code(404); echo 'Not found';
