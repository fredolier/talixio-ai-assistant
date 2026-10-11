<?php
// Fake streaming LLM server for tests: Anthropic /v1/messages and OpenAI /chat/completions (SSE),
// Gemini generateContent (JSON). First call asks for the list_tasks tool, the second answers.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string)file_get_contents('php://input');
$req = json_decode($raw, true) ?: [];
file_put_contents(getenv('TALIXIO_FAKE_LOG') ?: sys_get_temp_dir() . '/fake-llm.log', str_replace("\n", ' ', $raw) . "\n", FILE_APPEND); // raw: keeps {} vs []
$ev = function (string $event, array $data) { echo ($event !== '' ? "event: $event\n" : '') . 'data: ' . json_encode($data) . "\n\n"; flush(); };
if (str_contains($path, ':generateContent')) {
    header('Content-Type: application/json');
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => "[00:00] Speaker 1: We decide to launch on Monday.\n[00:05] Speaker 2: Fred will send the invoice by Friday."]]]]],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 50]]);
    return;
}
header('Content-Type: text/event-stream');
if ($path === '/v1/messages') {
    $last = end($req['messages']);
    $toolResult = is_array($last['content']) && ($last['content'][0]['type'] ?? '') === 'tool_result';
    if (($req['tool_choice']['type'] ?? '') === 'none') { // out of tool steps: the last answer
        $ev('message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 100, 'output_tokens' => 1]]]);
        $ev('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
        $ev('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Where things stand: ' . end($last['content'])['text']]]);
        $ev('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        $ev('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 9]]);
        $ev('message_stop', ['type' => 'message_stop']);
        return;
    }
    $ev('message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 100, 'output_tokens' => 1]]]);
    if ($toolResult) {
        $ev('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
        foreach (['Here ', 'are ', 'your tasks: ', $last['content'][0]['content']] as $t) $ev('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $t]]);
        $ev('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        $ev('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]]);
    } else {
        $ev('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]);
        $ev('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me check.']]);
        $ev('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIG123']]);
        $ev('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
        $ev('content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'list_tasks', 'input' => []]]);
        $ev('content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '']]);
        $ev('content_block_stop', ['type' => 'content_block_stop', 'index' => 1]);
        $ev('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]]);
    }
    $ev('message_stop', ['type' => 'message_stop']);
    return;
}
// OpenAI-compatible
$last = end($req['messages']);
if (($req['tool_choice'] ?? '') === 'none') { // out of tool steps: the last answer
    $ev('', ['choices' => [['delta' => ['content' => 'Where things stand: ' . $last['content']]]]]);
    $ev('', ['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]]);
} elseif ($last['role'] === 'tool') {
    foreach (['Done', ': ', $last['content']] as $t) $ev('', ['choices' => [['delta' => ['content' => $t]]]]);
    $ev('', ['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]]);
} else {
    $ev('', ['choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'list_', 'arguments' => '']]]]]]]);
    $ev('', ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => 'tasks', 'arguments' => '{}'], 'extra_content' => ['google' => ['thought_signature' => 'S1']]]]]]]]);
    $ev('', ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 3]]);
}
echo "data: [DONE]\n\n";
