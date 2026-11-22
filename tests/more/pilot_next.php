<?php
declare(strict_types=1);
// 1.51.0: when a board session ends its turn, the assistant takes it in hand. It reads the turn even when it may not
// answer by itself (that stays the user's opt-in): it says what the agent did and what to do next, proposes the reply
// (a choice among the options offered, or the next step towards the task), and when it does not send it, it tells
// the user, who decides. The user's answer then scores what it expected, so it learns.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Pilot};

$d = test_dir('pilot-next');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub(); $hub->token();
$pilot = $app->pilot();
$asked = []; $reply = '';
$before = Pilot::$model;
Pilot::$model = function (string $p) use (&$asked, &$reply) { $asked[] = $p; return $reply; };
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], 'laptop');
$queued = fn(string $id) => $app->store->q("SELECT by, text FROM agent_commands WHERE session = ? AND delivered IS NULL", [$id])->fetchAll();
$notes = fn() => $app->store->q('SELECT title, body FROM notifications ORDER BY id')->fetchAll();

// a status question; the agent answers with options and stops
$ev('s1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'What is the status of the shop project?']);
[, $r] = $ev('s1', ['hook_event_name' => 'Stop', 'last_assistant_message' => "Status: 3 of 5 tasks done, the payment page is late.\nA) finish the payment page\nB) write the tests first\nC) stop here"]);
check('pilot next: out of the box, a finished turn wakes the assistant', $pilot->state()['board_wake'] > 0, json_encode($pilot->overview()));
$reply = '{"kind":"answer","summary":"3 of 5 tasks are done; the payment page is late. The agent offers three ways on.","answer":"A","level":"L2","topic":"delivery","confidence":0.8,"rationale":"Client deliveries first."}';
$pilot->boardTick(time());
check('pilot next: it reads the turn, with the task the user gave', count($asked) === 1 && str_contains($asked[0], 'What is the status of the shop project?') && str_contains($asked[0], 'summary'), $asked[0] ?? '');
$sug = $hub->thread('claude-code:s1')['suggestion'];
check('pilot next: it says what the agent did and proposes a choice, and does not send it by itself', ($sug['answer'] ?? '') === 'A' && str_contains($sug['summary'] ?? '', 'payment page is late')
    && !$queued('claude-code:s1') && str_contains($sug['why'] ?? '', 'does not answer by itself'), json_encode($sug));
$n = $notes();
check('pilot next: the user is told an agent waits for a decision, with the analysis and the proposal', count($n) === 1 && str_contains($n[0]['body'], 'payment page is late') && str_contains($n[0]['body'], 'A'), json_encode($n));

// the user picks another option: scored, and it is a precedent for next time
$hub->command('claude-code:s1', 'reply', 'B');
$p = array_values(array_filter($pilot->state()['predictions'], fn($p) => $p['agent'] === 'claude-code:s1'))[0];
check('pilot next: the user\'s choice scores what it expected', $p['hit'] === false && $p['actual'] === 'B', json_encode($p));

// a turn that only reports: it proposes the next step towards the task, never sends it alone, and tells the user
$pilot->setPolicy(['enabled' => true, 'threshold' => 0.5, 'autonomy' => ['L1' => 'auto', 'L2' => 'auto', 'L3' => 'auto']]);
$ev('s2', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Add a contact form to the site']);
[, $r] = $ev('s2', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'The contact form is in place.']);
$reply = '{"kind":"next","summary":"The form is in place but untested.","answer":"Now add a test for the form.","level":"L1","topic":"delivery","confidence":0.95,"rationale":"The task is not finished without a test."}';
$pilot->boardTick(time() + 1000);
$sug = $hub->thread('claude-code:s2')['suggestion'];
check('pilot next: a next step it thought of itself is proposed, never sent alone', ($sug['answer'] ?? '') === 'Now add a test for the form.' && ($sug['kind'] ?? '') === 'next' && !$queued('claude-code:s2'), json_encode($sug));
check('pilot next: and the user is told', count($notes()) === 2);

// a choice the agent offered, within the limits once autonomy is on: sent, and the session goes on
$ev('s3', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Tidy the CSS']);
$ev('s3', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Tabs or spaces? A) tabs B) spaces']);
$reply = '{"kind":"answer","summary":"It asks for the indentation.","answer":"B","level":"L1","topic":"code","confidence":0.95,"rationale":"x"}';
$pilot->boardTick(time() + 2000);
check('pilot next: a choice the agent offered is sent within the limits', $queued('claude-code:s3') === [['by' => 'assistant', 'text' => 'B']] && count($notes()) === 2);

// a finished task: nothing to do, nobody bothered
$ev('s4', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Fix the typo']);
$ev('s4', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Fixed the typo, the task is done.']);
$reply = '{"kind":"done","summary":"Done."}';
$pilot->boardTick(time() + 3000);
check('pilot next: a task that is done is left alone, without a notice', !$queued('claude-code:s4') && !$hub->thread('claude-code:s4')['suggestion'] && count($notes()) === 2);

// « Resume and re-read »: a session that ended waits again, the assistant reads where it stopped, the answer resumes it
$pilot->setPolicy(['enabled' => false]);
$ev('t1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Check the shop project status']);
$ev('t1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Which part: the cart or the checkout?']);
$ev('t1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'The cart']);
$ev('t1', ['hook_event_name' => 'Stop', 'last_assistant_message' => "Status done.\nA) fix the cart B) leave it"]);
$ev('t1', ['hook_event_name' => 'SessionEnd', 'reason' => 'exit']);
$threw = fn(callable $f) => (function () use ($f) { try { $f(); return ''; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } })();
check('pilot next: resuming needs the machine\'s listener', str_contains($threw(fn() => $hub->reopen('claude-code:t1')), 'not listening'));
$hub->next('laptop');
$reply = '{"kind":"answer","summary":"The status is in; the cart is broken.","answer":"A","level":"L2","topic":"delivery","confidence":0.7,"rationale":"x"}';
$k = count($asked);
$r = $hub->reopen('claude-code:t1');
$s = $r['session'];
check('pilot next: an ended session waits again, carried by the listener, and is read at once', $s['state'] === 'waiting' && $s['job'] > 0 && $r['analysis'] === 'proposed' && count($asked) === $k + 1
    && str_contains($asked[$k], 'Check the shop project status') && ($hub->thread('claude-code:t1')['suggestion']['answer'] ?? '') === 'A', json_encode($r));
check('pilot next: the read sees the exchange before the turn', str_contains($asked[$k], "Agent: Which part: the cart or the checkout?\nUser: The cart\nTASK>>>"), $asked[$k]);
$hub->command('claude-code:t1', 'reply', 'A');
check('pilot next: the answer resumes the same session with claude -p --resume', $hub->next('laptop') === [200, "resume\n{$s['job']}\n/home/me/shop\nt1\nA"]);
check('pilot next: a session that has not ended is answered, not resumed', str_contains($threw(fn() => $hub->reopen('claude-code:s3')), 'answer it instead'));

// the user can still turn the reading off
$pilot->setPolicy(['analyse' => false, 'enabled' => false]);
$ev('s5', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Shall I go on?']); $k = count($asked);
$pilot->boardTick(time() + 4000);
check('pilot next: with the reading off, the model is not asked', count($asked) === $k && $pilot->overview()['analyse'] === false);

Pilot::$model = $before;
foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
