<?php
// Fake MCP server for tests: stdio when run from the command line, Streamable HTTP under `php -S`.
// Tools: lookup (read-only), create_note (changes something), fail (returns isError).
$tools = [
    ['name' => 'lookup', 'description' => 'Look up a word', 'inputSchema' => ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'required' => ['word']], 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'create_note', 'description' => 'Create a note', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
    ['name' => 'fail', 'description' => 'Always fails', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
];
$handle = function (array $m) use ($tools): ?array {
    if (!isset($m['id'])) return null;
    $r = match ($m['method']) {
        'initialize' => ['protocolVersion' => $m['params']['protocolVersion'], 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'fake', 'version' => '1']],
        'tools/list' => ['tools' => $tools],
        'tools/call' => match ($m['params']['name']) {
            'lookup' => ['content' => [['type' => 'text', 'text' => 'definition of ' . $m['params']['arguments']['word'] . ' (' . (getenv('FAKE_TOKEN') ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? 'no token')) . ')']]],
            'create_note' => ['content' => [['type' => 'text', 'text' => 'note created: ' . $m['params']['arguments']['text']]]],
            default => ['content' => [['type' => 'text', 'text' => 'it broke']], 'isError' => true],
        },
        default => null,
    };
    return $r === null ? ['jsonrpc' => '2.0', 'id' => $m['id'], 'error' => ['code' => -32601, 'message' => 'no']] : ['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $r];
};
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "fake mcp ready\n");
    while (($line = fgets(STDIN)) !== false) {
        $m = json_decode($line, true);
        if (is_array($m) && ($out = $handle($m))) { echo json_encode($out), "\n"; fflush(STDOUT); }
    }
    return;
}
$m = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (($m['method'] ?? '') === 'initialize') header('Mcp-Session-Id: s-123');
elseif (($_SERVER['HTTP_MCP_SESSION_ID'] ?? '') !== 's-123') { http_response_code(400); echo 'missing session'; return; }
$out = $handle($m);
if ($out === null) { http_response_code(202); return; }
if (($m['method'] ?? '') === 'tools/call') { header('Content-Type: text/event-stream'); echo "event: message\ndata: " . json_encode($out) . "\n\n"; return; }
header('Content-Type: application/json'); echo json_encode($out);
