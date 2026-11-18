<?php
declare(strict_types=1);
// 1.38.0: starting and stopping coding-agent sessions from the board. A listener script on the machine asks for work,
// runs Claude Code in a folder that machine already reported, and ends the sessions it started; any other session
// stops at its next hook, which answers {"continue": false}.
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App, Tools};

$d = test_dir('agent-start');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$hub->token();
$sess = fn(string $id) => array_values(array_filter($hub->board()['sessions'], fn($s) => $s['id'] === $id))[0] ?? null;
$ev = fn(string $sid, array $e, string $m = 'laptop') => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], $m);
$err = function (callable $f) { try { $f(); return ''; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };

// the machine reported one session in /home/me/shop, but its listener is not running yet
$ev('s0', ['hook_event_name' => 'SessionStart']);
$m = $hub->machines()[0];
check('agent start: a machine offers the folders its sessions reported', $m['machine'] === 'laptop' && $m['folders'] === ['/home/me/shop'] && !$m['listening'], json_encode($m));
check('agent start: no start while its listener is not running', str_contains($err(fn() => $hub->start('laptop', '/home/me/shop', 'Fix the tests')), 'not listening'));
check('agent start: the first ask of the listener makes the machine listening, with nothing to do', $hub->next('laptop') === [204, ''] && $hub->machines()[0]['listening']);
check('agent start: only a folder the machine reported, only a machine that reported, never an empty prompt',
    str_contains($err(fn() => $hub->start('laptop', '/etc', 'x')), 'folder this machine already reported')
    && str_contains($err(fn() => $hub->start('desktop', '/home/me/shop', 'x')), 'No machine')
    && $err(fn() => $hub->start('laptop', '/home/me/shop', '  ')) === 'Write what the session should do.');

$j = $hub->start('laptop', '/home/me/shop', "Fix the tests\nthen say done")['job'];
$b = $hub->board();
check('agent start: the new session waits on the board for its machine', $b['jobs'][0]['id'] === $j['id'] && $b['jobs'][0]['state'] === 'queued');
check('agent start: another machine\'s listener does not get it', $hub->next('desktop') === [204, '']);
[$code, $body] = $hub->next('laptop');
check('agent start: the listener gets the job, its folder and the prompt (lines it can read)', $code === 200 && $body === "start\n{$j['id']}\n/home/me/shop\nFix the tests\nthen say done", $body);
check('agent start: it is handed out once', $hub->next('laptop') === [204, ''] && $hub->job($j['id'])['state'] === 'sent');
check('agent start: only the machine it was sent to reports on it', $hub->jobReport('desktop', $j['id'], 'started', 4242, '')[0] === 404);
$hub->jobReport('laptop', $j['id'], 'started', 4242, '');
check('agent start: started, with its process', $hub->job($j['id'])['state'] === 'started' && (int)$hub->job($j['id'])['pid'] === 4242);

// its hooks report with TALIXIO_JOB: the session is linked to the job
[, $r] = $ev('s1', ['hook_event_name' => 'SessionStart', 'talixio_job' => $j['id']]);
$ev('s1', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => "Fix the tests\nthen say done", 'talixio_job' => $j['id']]);
$s = $sess('claude-code:s1');
$th = $hub->thread('claude-code:s1')['events'];
check('agent start: its first event links the session to the job, which leaves the waiting list', $s['job'] === $j['id'] && !$hub->board()['jobs'] && $hub->job($j['id'])['session'] === 'claude-code:s1');
check('agent start: its thread starts with the prompt sent from here, shown once', count($th) === 1 && $th[0]['kind'] === 'started' && $th[0]['who'] === 'you', json_encode($th));
$ev('s1', ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'npm test']]);
check('agent start: what it is doing shows on its card', $sess('claude-code:s1')['doing'] === 'Bash: npm test' && $sess('claude-code:s1')['column'] === 'working');
check('agent start: a job id from another machine links nothing', $ev('s9', ['hook_event_name' => 'SessionStart', 'talixio_job' => $j['id']], 'desktop')[0] === 200 && $sess('claude-code:s9')['job'] === 0);

// stopping a session the listener started: the listener ends its process
$r = $hub->stop('claude-code:s1');
check('agent start: Stop on a session it started goes to the listener', $r['how'] === 'kill' && $r['session']['stopping']);
check('agent start: the listener gets the stop, naming the job (never a process id)', $hub->next('laptop') === [200, "stop\n{$j['id']}\n"] && $hub->next('laptop') === [204, '']);
$hub->jobReport('laptop', $j['id'], 'exited', 0, 'killed');
$s = $sess('claude-code:s1');
check('agent start: once its process is gone the session shows stopped, in Quiet', $s['state'] === 'stopped' && $s['column'] === 'quiet' && !$s['stopping'], json_encode($s));
check('agent start: a stopped session takes no reply', str_contains($err(fn() => $hub->command('claude-code:s1', 'reply', 'hello')), 'stopped') && str_contains($err(fn() => $hub->stop('claude-code:s1')), 'not running'));

// any other session: its next hook answers {"continue": false}
$ev('s2', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Refactor']);
$r = $hub->stop('claude-code:s2');
check('agent start: Stop on a session typed in a terminal waits for its next step', $r['how'] === 'next' && $sess('claude-code:s2')['stopping']);
[$code, $r] = $ev('s2', ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => 'a.php']]);
check('agent start: its next tool call is told to stop', $code === 200 && ($r['stop'] ?? false) === true && $sess('claude-code:s2')['state'] === 'stopped');
check('agent start: the thread says who asked and when it stopped', array_column(array_slice($hub->thread('claude-code:s2')['events'], -2), 'kind') === ['stop', 'stopped']);
[, $r] = $ev('s2', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Go on after all']);
[, $r2] = $ev('s2', ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Edit', 'tool_input' => []]);
check('agent start: typing in the terminal again takes over: no stop left over', !isset($r['stop']) && !isset($r2['stop']) && $sess('claude-code:s2')['state'] === 'working');

[, $r] = $ev('s3', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'rm -rf /tmp/x']]);
$hub->stop('claude-code:s3');
[$code, $out] = $hub->wait('claude-code', 's3', 'permission', $r['seq']);
$o = json_decode($out, true);
check('agent start: a session waiting for a permission is denied and stopped at once', $code === 200 && $o['continue'] === false && $o['hookSpecificOutput']['decision']['behavior'] === 'deny'
    && $sess('claude-code:s3')['state'] === 'stopped', $out);
[, $r] = $ev('s4', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Done. Deploy?']);
$r2 = $hub->stop('claude-code:s4');
check('agent start: a session that finished its turn is stopped now, and its hook stops waiting for a reply', $r2['how'] === 'now' && $hub->wait('claude-code', 's4', 'reply', $r['seq'])[0] === 410);

// a job cancelled, a job that failed, a job that exited without ever reporting
$j2 = $hub->start('laptop', '/home/me/shop', 'Later')['job'];
check('agent start: a session not started yet is cancelled', $hub->stop('job:' . $j2['id'])['how'] === 'cancelled' && $hub->next('laptop') === [204, '']);
$j3 = $hub->start('laptop', '/home/me/shop', 'Now')['job'];
$hub->next('laptop'); $hub->stop('job:' . $j3['id']);
check('agent start: cancelled on its way: the listener is told to end it as it starts', ($hub->jobReport('laptop', $j3['id'], 'started', 99, '')[1]['stop'] ?? false) === true);
$j4 = $hub->start('laptop', '/home/me/shop', 'Again')['job'];
$hub->next('laptop'); $hub->jobReport('laptop', $j4['id'], 'failed', 0, 'Claude Code (claude) is not installed or not on the PATH of this listener.');
$f = array_values(array_filter($hub->board()['jobs'], fn($x) => $x['id'] === $j4['id']))[0] ?? null;
check('agent start: a session that could not start says why, on the board', $f && $f['state'] === 'failed' && str_contains($f['log'], 'not installed'));
$j5 = $hub->start('laptop', '/home/me/shop', 'Once more')['job'];
$hub->next('laptop'); $hub->jobReport('laptop', $j5['id'], 'started', 7, ''); $hub->jobReport('laptop', $j5['id'], 'exited', 0, 'Not logged in. Please run /login');
check('agent start: one that ended without ever reporting shows as failed, with what it printed', $hub->job($j5['id'])['state'] === 'failed' && str_contains($hub->job($j5['id'])['log'], '/login'));
$hub->forgetJob($j4['id']);
check('agent start: Forget removes it', $hub->job($j4['id']) === null);

// the assistant
$T = $app->tools->forAnswer();
check('agent start: agent_start and agent_stop ask first', $T->needsApproval('agent_start') && $T->needsApproval('agent_stop')
    && in_array('agent_start', Tools::EFFECTS, true) && in_array('agent_stop', Tools::EFFECTS, true));
$sum = $hub->summary();
check('agent start: agents_list names the machines that can start one, with their folders', str_contains($sum, 'laptop: /home/me/shop') && str_contains($sum, 'agent_start'), $sum);
$before = count($hub->board()['jobs']);
$res = $app->tools->run('agent_start', ['machine' => 'laptop', 'folder' => '/home/me/shop', 'prompt' => 'Write the changelog']);
check('agent start: the assistant asks before starting one, saying where and what (nothing queued yet)', str_contains($res, 'Start Claude Code on laptop in /home/me/shop: Write the changelog')
    && count($hub->board()['jobs']) === $before, $res);
$app->cfg->autoApprove[] = 'agent_start'; // as when the person approved it
$res = $app->tools->run('agent_start', ['machine' => 'laptop', 'folder' => '/home/me/shop', 'prompt' => 'Write the changelog']);
check('agent start: once approved, it is queued for the machine', str_starts_with($res, 'queued as job:') && count($hub->board()['jobs']) === $before + 1, $res);

// the scripts
$sh = $hub->script('laptop', 'agent-listen.sh');
check('agent start: the listener carries the address, the token and the machine', str_contains($sh, "T='" . $hub->token() . "'") && str_contains($sh, "M='laptop'") && !str_contains($sh, '@URL@'));
$f = "$d/listen.sh"; file_put_contents($f, $sh); exec('sh -n ' . escapeshellarg($f) . ' 2>&1', $o2, $rc);
check('agent start: the listener is valid sh', $rc === 0, implode("\n", $o2));
check('agent start: the hooks now include PreToolUse (where a stop takes effect), and the hook script passes its job', isset(AgentHub::claudeSettings()['hooks']['PreToolUse'])
    && str_contains($hub->script('laptop'), 'job=${TALIXIO_JOB:-}') && str_contains($hub->script('laptop'), '"continue":false'));

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
