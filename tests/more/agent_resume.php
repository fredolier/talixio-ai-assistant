<?php
declare(strict_types=1);
// 1.50.0: a session started from the board (claude -p) is continued from the board with claude -p --resume, so the
// reply reaches Claude Code as a prompt, the way the board's first prompt did, and not as a hook's feedback (which
// Claude Code does not take as the person's go-ahead for a sensitive step).
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App};

$d = test_dir('agent-resume');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$hub->token();
$sess = fn(string $id) => array_values(array_filter($hub->board()['sessions'], fn($s) => $s['id'] === $id))[0] ?? null;
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], 'laptop');
$kinds = fn(string $id) => array_map(fn($e) => $e['who'] . ':' . $e['kind'], $hub->thread($id)['events']);

$ev('s0', ['hook_event_name' => 'SessionStart']);
$hub->next('laptop'); // the listener runs
$j = $hub->start('laptop', '/home/me/shop', 'Prepare the release')['job'];
$hub->next('laptop'); $hub->jobReport('laptop', $j['id'], 'started', 41, '');
$ev('r1', ['hook_event_name' => 'SessionStart', 'talixio_job' => $j['id']]);
$ev('r1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Prepare the release', 'talixio_job' => $j['id']]);
[, $r] = $ev('r1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Ready. Shall I push? A) push B) wait', 'talixio_job' => $j['id']]);
$seq = $r['seq'];
// claude -p ends with its turn: Claude Code says so with SessionEnd (reason « other ») before the listener sees the process end
$ev('r1', ['hook_event_name' => 'SessionEnd', 'reason' => 'other', 'talixio_job' => $j['id']]);
check('agent resume: the SessionEnd of claude -p after a turn that waits for you does not end the session (1.52.2)', $sess('claude-code:r1')['state'] === 'waiting'
    && $sess('claude-code:r1')['column'] === 'need' && (int)$hub->get('claude-code:r1')['seq'] === (int)$seq);
$hub->jobReport('laptop', $j['id'], 'exited', 0, 'Ready.');
$s = $sess('claude-code:r1');
check('agent resume: a board-started session that finished its turn stays answerable when its process ends', $s['state'] === 'waiting' && $s['column'] === 'need'
    && $hub->job($j['id'])['state'] === 'exited', json_encode($s));
check('agent resume: its Stop hook is not the way back in: a reply is never handed to it', $hub->wait('claude-code', 'r1', 'reply', $seq)[0] === 410);

$c = $hub->command('claude-code:r1', 'reply', 'A');
check('agent resume: a reply to it is queued for the machine\'s listener', $hub->job($j['id'])['state'] === 'resume' && $c['session']['pending'] === 1);
[$code, $body] = $hub->next('laptop');
check('agent resume: the listener gets the job, the folder, the session to resume and the reply, as lines', $code === 200 && $body === "resume\n{$j['id']}\n/home/me/shop\nr1\nA", $body);
// 1.52.1: handed out, but only delivered once the listener says it started (it was counted delivered at hand-out)
check('agent resume: handed out once, not counted delivered before the listener starts it', $hub->next('laptop') === [204, '']
    && $hub->job($j['id'])['state'] === 'sent' && $sess('claude-code:r1')['state'] === 'waiting' && $sess('claude-code:r1')['pending'] === 1
    && !in_array('you:delivered', $kinds('claude-code:r1'), true));
$hub->jobReport('laptop', $j['id'], 'started', 42, '');
check('agent resume: started again, with its new process: delivered, and the session is working again', $hub->job($j['id'])['state'] === 'running' && (int)$hub->job($j['id'])['pid'] === 42
    && $sess('claude-code:r1')['state'] === 'working' && $sess('claude-code:r1')['pending'] === 0 && in_array('you:delivered', $kinds('claude-code:r1'), true));
$n = count($hub->thread('claude-code:r1')['events']);
$ev('r1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'A', 'talixio_job' => $j['id']]);
check('agent resume: the resumed prompt is the reply already shown: not shown twice', count($hub->thread('claude-code:r1')['events']) === $n, implode(' ', $kinds('claude-code:r1')));

// a reply sent while it still works waits for the end of the turn, then goes the same way
$hub->command('claude-code:r1', 'reply', 'And tag it.');
check('agent resume: while it works, a reply waits', $hub->next('laptop') === [204, '']);
$ev('r1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Pushed.', 'talixio_job' => $j['id']]);
$hub->jobReport('laptop', $j['id'], 'exited', 0, 'Pushed.');
check('agent resume: once the process ends, the waiting reply resumes it', $hub->next('laptop') === [200, "resume\n{$j['id']}\n/home/me/shop\nr1\nAnd tag it."]);
$hub->jobReport('laptop', $j['id'], 'started', 43, '');

// a process that ends in the middle of its work, or stopped: it ends, as before
$hub->jobReport('laptop', $j['id'], 'exited', 0, 'Killed');
check('agent resume: a process that ends in the middle of a turn ends the session', $sess('claude-code:r1')['state'] === 'ended');
$threw = ''; try { $hub->command('claude-code:r1', 'reply', 'hello?'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
check('agent resume: an ended session takes no reply', $threw === 'This session has ended.');

// 1.52.1: the process is still alive after its turn (Claude Code waiting for its background tasks): the reply waits for its end
$j2 = $hub->start('laptop', '/home/me/shop', 'Run the scenario')['job'];
$hub->next('laptop'); $hub->jobReport('laptop', $j2['id'], 'started', 50, '');
$ev('r2', ['hook_event_name' => 'SessionStart', 'talixio_job' => $j2['id']]);
$ev('r2', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Run the scenario', 'talixio_job' => $j2['id']]);
$ev('r2', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Keep the pause? A) yes B) no', 'talixio_job' => $j2['id']]);
$hub->jobReport('laptop', $j2['id'], 'exited', 0, '');
$hub->command('claude-code:r2', 'reply', 'A');
check('agent resume: offered to the listener', $hub->next('laptop')[0] === 200);
$hub->jobReport('laptop', $j2['id'], 'busy', 50, '');
check('agent resume: a listener whose process still runs says so: the reply stays queued, the thread says why', $hub->job($j2['id'])['state'] === 'running'
    && $sess('claude-code:r2')['pending'] === 1 && $sess('claude-code:r2')['state'] === 'waiting' && in_array('agent:notice', $kinds('claude-code:r2'), true)
    && $hub->next('laptop') === [204, '']);
$hub->jobReport('laptop', $j2['id'], 'exited', 0, '');
check('agent resume: once that process ends, the reply resumes it', $hub->next('laptop') === [200, "resume\n{$j2['id']}\n/home/me/shop\nr2\nA"]);

// 1.52.1: a listener that never confirms (one older than 1.50.0 ignores « resume »): offered again, never lost
$app->store->q('UPDATE agent_jobs SET updated = updated - 100 WHERE id = ?', [$j2['id']]);
$hub->command('claude-code:r2', 'reply', 'And show me the counts.');
check('agent resume: unconfirmed, it is offered again with every reply waiting', $hub->next('laptop') === [200, "resume\n{$j2['id']}\n/home/me/shop\nr2\nA\n\nAnd show me the counts."]
    && $sess('claude-code:r2')['pending'] === 2);
check('agent resume: not again before the wait is over', $hub->next('laptop') === [204, '']);
check('agent resume: two offers unanswered say nothing yet', !str_contains(implode(' ', array_column($hub->thread('claude-code:r2')['events'], 'text')), 'update the listener'));
$app->store->q('UPDATE agent_jobs SET updated = updated - 100 WHERE id = ?', [$j2['id']]); $hub->next('laptop');
check('agent resume: after three unanswered offers the thread says the listener needs updating', str_contains(implode(' ', array_column($hub->thread('claude-code:r2')['events'], 'text')), 'update the listener'));
$hub->jobReport('laptop', $j2['id'], 'started', 51, '');
check('agent resume: once it starts, both replies are delivered', $sess('claude-code:r2')['pending'] === 0 && $sess('claude-code:r2')['state'] === 'working');
check('agent resume: the listener script says when a session is still running', str_contains($hub->script('laptop', 'agent-listen.sh'), 'report "$id" busy'));

// a SessionEnd in the middle of its work, or of a session typed in a terminal, still ends it
$j3 = $hub->start('laptop', '/home/me/shop', 'Tidy')['job'];
$hub->next('laptop'); $hub->jobReport('laptop', $j3['id'], 'started', 60, '');
$ev('r3', ['hook_event_name' => 'SessionStart', 'talixio_job' => $j3['id']]);
$ev('r3', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Tidy', 'talixio_job' => $j3['id']]);
$ev('r3', ['hook_event_name' => 'SessionEnd', 'reason' => 'other', 'talixio_job' => $j3['id']]);
$ev('t0', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Done?']);
$ev('t0', ['hook_event_name' => 'SessionEnd', 'reason' => 'prompt_input_exit']);
check('agent resume: a SessionEnd mid-turn, or of a terminal session, still ends it', $sess('claude-code:r3')['state'] === 'ended' && $sess('claude-code:t0')['state'] === 'ended');

// a session typed in a terminal keeps the hook's way
[, $r] = $ev('t1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Done?']);
$hub->command('claude-code:t1', 'reply', 'Yes');
check('agent resume: a session not started from the board still gets its reply through its hook', $hub->wait('claude-code', 't1', 'reply', $r['seq'])[0] === 200
    && $hub->next('laptop') === [204, '']);

// the listener resumes only a session id it can pass safely
check('agent resume: the listener script resumes with --resume, and checks the session id', str_contains($hub->script('laptop', 'agent-listen.sh'), '--resume "$sid"')
    && str_contains($hub->script('laptop', 'agent-listen.sh'), "*[!A-Za-z0-9_-]*"));

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
