<?php
declare(strict_types=1);
// 1.36.0: the agent board. Coding agents report themselves through a hook script (events in, word for word, with their
// real time) and pick up the answers given in Talixio; no model relays anything. Before this, the board was what a
// third Claude session chose to write about the others, every ~10 minutes.
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App, Tools};

$d = test_dir('agent-hub');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$col = fn(string $id) => array_values(array_filter($hub->board()['sessions'], fn($s) => $s['id'] === $id))[0] ?? null;
$ev = fn(array $e) => $hub->event($e + ['session_id' => 's1', 'cwd' => '/home/me/shop'], 'laptop');

check('agent hub: no token until a machine is connected, and none is accepted', !$hub->authorized('') && !$hub->authorized('x'));
$tok = $hub->token();
check('agent hub: the token issued is the one accepted', $hub->authorized($tok) && !$hub->authorized($tok . 'x') && $hub->token() === $tok);
check('agent hub: a new token replaces the old one', $hub->token(true) !== $tok && !$hub->authorized($tok));

[$code, $r] = $ev(['hook_event_name' => 'SessionStart']);
check('agent hub: a session appears on its first event, named by its folder and machine', $code === 200 && $r['id'] === 'claude-code:s1'
    && ($s = $col('claude-code:s1')) && $s['project'] === 'shop' && $s['machine'] === 'laptop' && $s['state'] === 'ready', json_encode($r));
check('agent hub: an event without a session or of an unknown kind is refused', $hub->event(['hook_event_name' => 'Stop'], 'x')[0] === 400
    && $ev(['hook_event_name' => 'Whatever'])[0] === 400);

$ev(['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Fix the checkout bug']);
check('agent hub: a prompt typed in the session puts it in Working and shows in its thread', $col('claude-code:s1')['column'] === 'working'
    && end($hub->thread('claude-code:s1')['events'])['text'] === 'Fix the checkout bug');

[, $r] = $ev(['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'rm -rf build']]);
$seq = $r['seq'];
$s = $col('claude-code:s1');
check('agent hub: a permission request goes to Needs you, said in one line', $s['column'] === 'need' && $s['question'] === 'Bash: rm -rf build', json_encode($s));
check('agent hub: nothing is answered yet: the hook keeps waiting', $hub->wait('claude-code', 's1', 'permission', $seq) === [204, '']);
check('agent hub: a hook holding an old sequence number stops waiting', $hub->wait('claude-code', 's1', 'permission', $seq - 1)[0] === 410);
$c = $hub->command('claude-code:s1', 'allow');
check('agent hub: "allow" says the session is listening when its hook just asked', $c['listening'] === true && $c['session']['pending'] === 1);
[$code, $body] = $hub->wait('claude-code', 's1', 'permission', $seq);
$out = json_decode($body, true);
check('agent hub: the hook gets Claude Code\'s own answer format', $code === 200 && $out['hookSpecificOutput']['hookEventName'] === 'PermissionRequest'
    && $out['hookSpecificOutput']['decision']['behavior'] === 'allow', $body);
check('agent hub: once answered, the session is back to Working and the answer is not given twice', $col('claude-code:s1')['state'] === 'working'
    && $hub->wait('claude-code', 's1', 'permission', $seq)[0] === 410);
$threw = false; try { $hub->command('claude-code:s1', 'deny'); } catch (\InvalidArgumentException) { $threw = true; }
check('agent hub: allow/deny is refused when no permission is asked', $threw);

[, $r] = $ev(['hook_event_name' => 'Stop', 'last_assistant_message' => "Fixed.\nShould I also add a test?"]);
$seq = $r['seq'];
$s = $col('claude-code:s1');
check('agent hub: the end of a turn shows its last message word for word, in Needs you', $s['column'] === 'need' && $s['state'] === 'waiting'
    && $s['last_message'] === "Fixed.\nShould I also add a test?");
$hub->markSeen('claude-code:s1');
check('agent hub: once looked at, it moves to Quiet', $col('claude-code:s1')['column'] === 'quiet');
$ev(['hook_event_name' => 'Notification', 'message' => 'Claude is waiting for your input', 'notification_type' => 'idle_prompt']);
check('agent hub: a notice alone does not bring it back to Needs you', $col('claude-code:s1')['column'] === 'quiet');
$threw = false; try { $hub->command('claude-code:s1', 'reply', '  '); } catch (\InvalidArgumentException) { $threw = true; }
check('agent hub: an empty reply is refused', $threw);
check('agent hub: before a reply, the waiting hook hears nothing', $hub->wait('claude-code', 's1', 'reply', $seq) === [204, '']);
$hub->command('claude-code:s1', 'reply', 'Yes, add a test.');
$hub->command('claude-code:s1', 'reply', 'And run the suite.');
[$code, $body] = $hub->wait('claude-code', 's1', 'reply', $seq);
check('agent hub: the hook gets the replies, in order, as plain text', $code === 200 && $body === "Yes, add a test.\n\nAnd run the suite.", $body);
check('agent hub: the session is Working again and its old wait is over', $col('claude-code:s1')['state'] === 'working' && $hub->wait('claude-code', 's1', 'reply', $seq)[0] === 410);
$kinds = array_map(fn($e) => $e['who'] . ':' . $e['kind'], $hub->thread('claude-code:s1')['events']);
check('agent hub: the thread records what was queued and when it was delivered', in_array('you:queued', $kinds, true) && in_array('you:delivered', $kinds, true), implode(' ', $kinds));

[, $r] = $ev(['hook_event_name' => 'Stop', 'last_assistant_message' => 'Done.']);
$hub->command('claude-code:s1', 'reply', 'thanks');
$ev(['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'typed in the terminal instead']);
check('agent hub: typing in the session itself ends the wait for a reply from here', $hub->wait('claude-code', 's1', 'reply', $r['seq'])[0] === 410);

$hub->event(['session_id' => 'x9', 'hook_event_name' => 'Stop', 'last_assistant_message' => 'codex here', 'agent' => 'Codex'], 'desk');
check('agent hub: another agent reports with its own name', ($o = $col('codex:x9')) && $o['agent'] === 'codex' && $o['last_message'] === 'codex here');
$hub->event(['session_id' => 'x9', 'hook_event_name' => 'SessionEnd', 'reason' => 'exit', 'agent' => 'codex'], 'desk');
$threw = false; try { $hub->command('codex:x9', 'reply', 'hello?'); } catch (\InvalidArgumentException) { $threw = true; }
check('agent hub: an ended session takes no answer and its hook stops', $threw && $col('codex:x9')['column'] === 'quiet' && $hub->wait('codex', 'x9', 'reply', 99)[0] === 410);
$hub->forget('codex:x9');
check('agent hub: forget removes the session and its thread', $col('codex:x9') === null && !$app->store->q("SELECT COUNT(*) FROM agent_events WHERE session = 'codex:x9'")->fetchColumn());

// the assistant reads the board and answers through it, with the person's approval
check('agent hub: agents_list only reads, and what it returns counts as written by others', in_array('agents_list', Tools::READS, true) && Tools::untrustedSource('agents_list'));
$T = $app->tools->forAnswer();
check('agent hub: agent_answer asks first', $T->needsApproval('agent_answer') && in_array('agent_answer', Tools::EFFECTS, true));
check('agent hub: the summary names the session, its state and its last words', str_contains($hub->summary(), 'claude-code:s1') && str_contains($hub->summary(), 'typed in the terminal') === false
    && str_contains($hub->summary(), 'Done.'), $hub->summary());

// the hook script and the settings block
$sh = $hub->script("lap'top");
check('agent hub: the script carries the address, the token and the machine (no quote can break out)', str_contains($sh, "T='" . $hub->token() . "'")
    && str_contains($sh, "M='laptop'") && !str_contains($sh, '@TOKEN@') && !str_contains($sh, '@URL@'));
$f = "$d/hook.sh"; file_put_contents($f, $sh); exec('sh -n ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
check('agent hub: the script is valid sh', $rc === 0, implode("\n", $o));
$st = AgentHub::claudeSettings();
check('agent hub: the settings run the script on each event; the end of a turn waits in the background (it wakes the session)',
    ($st['hooks']['Stop'][0]['hooks'][0]['asyncRewake'] ?? false) === true && str_ends_with($st['hooks']['Stop'][0]['hooks'][0]['command'], ' reply')
    && str_ends_with($st['hooks']['PermissionRequest'][0]['hooks'][0]['command'], ' permission') && isset($st['hooks']['SessionEnd'], $st['hooks']['UserPromptSubmit']));

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
