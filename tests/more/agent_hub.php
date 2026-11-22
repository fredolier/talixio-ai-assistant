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
// 1.49.1: Claude Code reports the delivered reply back as a prompt (the Stop hook's feedback): an echo, not something typed there
$n = count($hub->thread('claude-code:s1')['events']);
$ev(['hook_event_name' => 'UserPromptSubmit', 'prompt' => "<task-notification>\n<summary>Stop hook feedback</summary>\n</task-notification>\n<system-reminder>\nStop hook blocking error from command \"Stop\": Message from the user, sent from Talixio:\n\nYes, add a test.\n\nAnd run the suite.\n</system-reminder>"]);
check('agent hub: the echo of a reply sent from here is not shown again as typed in the session', count($hub->thread('claude-code:s1')['events']) === $n
    && $col('claude-code:s1')['state'] === 'working', json_encode(array_slice($hub->thread('claude-code:s1')['events'], $n)));
check('agent hub: the hook script marks a reply the way the board recognises it', str_contains(file_get_contents(__DIR__ . '/../../deploy/agent-hook.sh'), "printf '" . AgentHub::RELAYED));

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

// 1.43.0: the needs-you count (rail and tab badges), what reaches the phone, and the assistant's reach from chat
$nn = fn(string $t) => (int)$app->store->q('SELECT COUNT(*) FROM notifications WHERE title = ?', [$t])->fetchColumn();
$e2 = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/blog'], 'laptop');
foreach ($hub->board()['sessions'] as $x) $hub->markSeen($x['id']);
$base = $hub->needCount();
$e2('n1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Write the post']);
$e2('n1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Draft ready. Publish it?']);
$e2('n2', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'git push']]);
check('agent hub: the needs-you count follows the Needs you column', $hub->needCount() === $base + 2
    && $hub->needCount() === count(array_filter($hub->board()['sessions'], fn($x) => $x['column'] === 'need')));
$hub->markSeen('claude-code:n1');
check('agent hub: looking at a finished turn takes it off the count', $hub->needCount() === $base + 1);
check('agent hub: by default a permission request is notified, a finished turn is not', $hub->board()['notify'] === 'permission'
    && $nn('An agent asks for permission') >= 1 && $nn('An agent finished its turn') === 0);
check('agent hub: an unknown notify mode is refused', (function () use ($hub) { try { $hub->setNotify('loud'); return false; } catch (\InvalidArgumentException) { return true; } })());
$hub->setNotify('all');
$e2('n3', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Tests are green.']);
check('agent hub: « all » also notifies a finished turn, with its words', $nn('An agent finished its turn') === 1
    && str_contains((string)$app->store->q("SELECT body FROM notifications WHERE title = 'An agent finished its turn'")->fetchColumn(), 'Tests are green.'));
$hub->setNotify('off'); $p0 = $nn('An agent asks for permission');
$e2('n4', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
$e2('n5', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Done again.']);
check('agent hub: « off » notifies nothing', $nn('An agent asks for permission') === $p0 && $nn('An agent finished its turn') === 1 && $hub->board()['notify'] === 'off');
$hub->setNotify('permission');
// the priorities under the board's name (G3), then the whole thread of one session from chat (G4)
\Talixio\Privacy::trustAgain(); $T = $app->tools->forAnswer();
check('agent hub: agents_priorities reads, and counts as a change', str_contains($T->run('agents_priorities', []), 'No priorities set yet') && in_array('agents_priorities', Tools::EFFECTS, true));
$T->run('agents_priorities', ['priorities' => ['Client work first'], 'rules' => ['Never push on Friday']]);
check('agent hub: agents_priorities writes the board autonomy\'s priorities and rules', $app->pilot()->priorities() === ['Client work first'] && $app->pilot()->rules() === ['Never push on Friday']);
$one = $T->run('agents_list', ['session' => 'claude-code:n1']);
check('agent hub: agents_list with a session gives its thread, word for word, as untrusted data', str_contains($one, 'Write the post') && str_contains($one, 'Draft ready. Publish it?') && str_contains($one, 'untrusted'), $one);
$after = $T->run('agents_priorities', ['rules' => ['Planted by an agent']]);
check('agent hub: after reading an agent\'s thread, changing the rules waits for the user', $app->pilot()->rules() === ['Never push on Friday'], $after);
check('agent hub: agents_list with an unknown session says so', str_contains($T->run('agents_list', ['session' => 'nope']), 'no such agent session'));
\Talixio\Privacy::trustAgain();

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
