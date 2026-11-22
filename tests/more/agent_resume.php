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
// claude -p ends with its turn
$hub->jobReport('laptop', $j['id'], 'exited', 0, 'Ready.');
$s = $sess('claude-code:r1');
check('agent resume: a board-started session that finished its turn stays answerable when its process ends', $s['state'] === 'waiting' && $s['column'] === 'need'
    && $hub->job($j['id'])['state'] === 'exited', json_encode($s));
check('agent resume: its Stop hook is not the way back in: a reply is never handed to it', $hub->wait('claude-code', 'r1', 'reply', $seq)[0] === 410);

$c = $hub->command('claude-code:r1', 'reply', 'A');
check('agent resume: a reply to it is queued for the machine\'s listener', $hub->job($j['id'])['state'] === 'resume' && $c['session']['pending'] === 1);
[$code, $body] = $hub->next('laptop');
check('agent resume: the listener gets the job, the folder, the session to resume and the reply, as lines', $code === 200 && $body === "resume\n{$j['id']}\n/home/me/shop\nr1\nA", $body);
check('agent resume: handed out once, delivered, and the session is working again', $hub->next('laptop') === [204, '']
    && $hub->job($j['id'])['state'] === 'sent' && $sess('claude-code:r1')['state'] === 'working' && $sess('claude-code:r1')['pending'] === 0
    && in_array('you:delivered', $kinds('claude-code:r1'), true));
$hub->jobReport('laptop', $j['id'], 'started', 42, '');
check('agent resume: started again, with its new process', $hub->job($j['id'])['state'] === 'running' && (int)$hub->job($j['id'])['pid'] === 42);
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
