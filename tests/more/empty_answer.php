<?php
declare(strict_types=1);
// 1.47.1: gpt-oss (AKI.IO, vLLM…) sometimes ends a turn with an empty answer after using its tools: all it said stayed
// in its reasoning. The chat showed "(no response)" although the action was done. Now the model is asked once for its
// answer; if it still says nothing, the reply names what was done.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, OpenAIProvider, Tier};

$d = test_dir('empty-answer');
$fake = "$d/fake"; @mkdir($fake);
// step 1: a tool call; step 2: empty content (reasoning only); the nudge (last message from the user, tool_choice none):
// an answer, unless "mute" is on (then always empty)
file_put_contents("$fake/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$req = json_decode((string)file_get_contents('php://input'), true) ?: [];
file_put_contents("$dir/log.jsonl", json_encode($req) . "\n", FILE_APPEND | LOCK_EX);
$msgs = $req['messages'] ?? []; $last = end($msgs);
$mute = is_file("$dir/mute");
$hasTool = (bool)array_filter($msgs, fn($m) => ($m['role'] ?? '') === 'tool');
if (!$hasTool) $msg = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'list_tasks', 'arguments' => '{}']]]];
elseif (($last['role'] ?? '') === 'user' && !$mute) $msg = ['role' => 'assistant', 'content' => 'Done: the watch now reads the Debian advisories feed.'];
else $msg = ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'The routine is updated; I should tell the user.'];
if (!empty($req['stream'])) {
    header('Content-Type: text/event-stream');
    $delta = isset($msg['tool_calls']) ? ['tool_calls' => [['index' => 0] + $msg['tool_calls'][0]]] : ['content' => (string)$msg['content'], 'reasoning_content' => $msg['reasoning_content'] ?? ''];
    echo 'data: ' . json_encode(['choices' => [['delta' => $delta]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5]]) . "\n\ndata: [DONE]\n\n";
    return;
}
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => $msg]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5]]);
PHP);
$port = free_port();
$fenv = ['PATH' => getenv('PATH')] + (getenv('PHP_INI_SCAN_DIR') !== false ? ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')] : []);
$fp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$fake/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fpp, null, $fenv);
register_shutdown_function(function () use ($fp) { proc_terminate($fp); proc_close($fp); });
wait_port($port);

$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d/app"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$p = new OpenAIProvider('k', new Tier('standard', 'gpt-oss-120b', 6), "http://127.0.0.1:$port/v1");
$calls = fn() => array_map(fn($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string)@file_get_contents("$fake/log.jsonl")))));
$msgs = [['role' => 'user', 'content' => 'Replace the Debian list in the watch routine.']];

$text = $p->complete('sys', '', $msgs, $app->tools->only('chat'), fn($u) => null);
$c = $calls();
check('empty answer: after its tools, an empty answer makes Talixio ask the model once for it', $text === 'Done: the watch now reads the Debian advisories feed.' && count($c) === 3, $text . ' / ' . count($c));
check('empty answer: the second request asks for the answer, without tools', ($c[2]['tool_choice'] ?? '') === 'none' && str_contains((string)(array_slice($c[2]['messages'] ?? [], -1)[0]['content'] ?? ''), 'what you did'), json_encode(array_slice($c[2]['messages'] ?? [], -1)));
check('empty answer: what the model thought is never shown as its answer', !str_contains($text, 'I should tell the user'));

@unlink("$fake/log.jsonl");
$ev = [];
$text = $p->complete('sys', '', $msgs, $app->tools->only('chat'), fn($u) => null, function ($e) use (&$ev) { $ev[] = $e; });
check('empty answer: the same when streaming, and the answer is streamed', $text === 'Done: the watch now reads the Debian advisories feed.' && count($calls()) === 3
    && implode('', array_column(array_filter($ev, fn($e) => $e['type'] === 'text'), 'text')) === $text, $text);

@unlink("$fake/log.jsonl"); touch("$fake/mute");
$text = $p->complete('sys', '', $msgs, $app->tools->only('chat'), fn($u) => null);
check('empty answer: asked only once: still empty, the provider returns empty', $text === '' && count($calls()) === 3, count($calls()) . " '$text'");

// a plain answer is never asked twice
@unlink("$fake/log.jsonl"); @unlink("$fake/mute");
file_put_contents("$fake/router.php", '<?php file_put_contents(__DIR__ . "/log.jsonl", "x\n", FILE_APPEND); header("Content-Type: application/json"); echo json_encode(["choices" => [["message" => ["role" => "assistant", "content" => "Hello."]]], "usage" => ["prompt_tokens" => 1, "completion_tokens" => 1]]);');
check('empty answer: a normal answer costs one request', $p->complete('sys', '', $msgs, $app->tools->only('chat'), fn($u) => null) === 'Hello.' && count(file("$fake/log.jsonl")) === 1);

// the chat: still nothing to say after tools ran → the reply names what was done, not "(no response)"
check('empty answer: the chat says what was done when the model said nothing', \Talixio\Agent::silentReply($app, ['routine_update', 'list_tasks', 'routine_update']) === 'Done, without a word from the model. Actions run: routine_update, list_tasks. Check the result before relying on it.');
check('empty answer: with no action, it says so plainly', \Talixio\Agent::silentReply($app, []) === '(no response)');
$app->cfg->lang = 'fr';
check('empty answer: in French too', str_starts_with(\Talixio\Agent::silentReply($app, ['routine_update']), 'Fait, sans un mot du modèle.'));

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
