<?php
declare(strict_types=1);
// 1.57.0: an objective. The board starts a session in a project and keeps it going until the checks you gave pass (the
// workflow's own commands, run by the listener), then asks it for its report. Its permission requests follow the rules
// set when it started, so it does not wait for you; it stops when it makes no progress, or runs out of runs or time.
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App, Pilot};

$d = test_dir('agent-goal');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$hub->token();
$app->pilot()->setPolicy(['analyse' => false]); // the assistant does not read the turns: the objective goes on at once
$err = function (callable $f) { try { $f(); return ''; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/w/shop'], 'vm');
$goal = fn(int $id) => $hub->goal($id);
$text = fn(string $sid) => implode("\n", array_column($hub->thread("claude-code:$sid")['events'], 'text'));

$ev('s0', ['hook_event_name' => 'SessionStart']);
$hub->next('vm'); // its listener runs
$checks = "./tools-workflow scenario:close-dates --scenario fw => E06c\n./tools-workflow ci:status --sha HEAD";
check('goal: a check is one plain command, never a risky one; at least one, at most 5',
    str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', 'E06c', 'Close it', 'make test; rm -rf /')), 'plain words')
    && str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', 'E06c', 'Close it', 'git push origin x')), 'risky')
    && str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', 'E06c', 'Close it', "\n")), 'at least one')
    && str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', 'E06c', 'Close it', str_repeat("ls\n", 6))), 'At most 5')
    && str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', ' ', 'Close it', 'ls')), 'name'));
$g = $hub->goalStart('vm', '/w/shop', 'Close epic E06c', 'Take epic E06c of scenario fw to its close, following the workflow.', $checks, ['git', 'bogus'])['goal'];
check('goal: it starts a session there, told the objective and its checks', $g['state'] === 'starting' && $g['allow'] === ['git'] && $g['checks'][0] === ['cmd' => './tools-workflow scenario:close-dates --scenario fw', 'expect' => 'E06c']
    && str_contains($hub->job($g['job'])['prompt'], '`./tools-workflow ci:status --sha HEAD` (exit code 0)') && str_contains($hub->job($g['job'])['prompt'], 'without waiting for a confirmation'));
[, $body] = $hub->next('vm');
$hub->jobReport('vm', $g['job'], 'started', 99, '');
$ev('g1', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g['job']]);
check('goal: its session is linked when it reports', $goal($g['id'])['session'] === 'claude-code:g1' && $goal($g['id'])['state'] === 'working' && $hub->inGoal('claude-code:g1'));

// its permission requests: the objective's rules, at once
$perm = function (string $tool, array $in) use ($hub, $ev) {
    [, $r] = $ev('g1', ['hook_event_name' => 'PermissionRequest', 'tool_name' => $tool, 'tool_input' => $in, 'talixio_job' => 0]);
    [$code, $b] = $hub->wait('claude-code', 'g1', 'permission', $r['seq']);
    return $code === 200 ? json_decode($b, true)['hookSpecificOutput']['decision'] : null;
};
check('goal: what changes things in the project is allowed without you', ($perm('Edit', ['file_path' => '/w/shop/src/a.php'])['behavior'] ?? '') === 'allow'
    && ($perm('Bash', ['command' => './tools-workflow scenario:decision:record x'])['behavior'] ?? '') === 'allow'
    && str_contains($text('g1'), "Allowed by the objective's rules"));
check('goal: a « Check first » kind you ticked is allowed: a push to its branch', ($perm('Bash', ['command' => 'git push origin feat/e06c'])['behavior'] ?? '') === 'allow');
$no = $perm('Bash', ['command' => 'npm install left-pad']);
check('goal: one you did not tick is refused at once, and the agent is told to do it another way', ($no['behavior'] ?? '') === 'deny' && str_contains($no['message'], 'another way')
    && str_contains($text('g1'), "Refused by the objective's rules: install"));
check('goal: never a push to main or a forced push, a file outside the project, its own settings, a deploy',
    ($perm('Bash', ['command' => 'git push origin main'])['behavior'] ?? '') === 'deny' && ($perm('Bash', ['command' => 'git push --force origin feat/x'])['behavior'] ?? '') === 'deny'
    && ($perm('Edit', ['file_path' => '/etc/hosts'])['behavior'] ?? '') === 'deny' && ($perm('Edit', ['file_path' => '/w/shop/.claude/settings.json'])['behavior'] ?? '') === 'deny'
    && ($perm('Bash', ['command' => './tools-workflow deploy:prod'])['behavior'] ?? '') === 'deny');
check('goal: a session outside an objective keeps the usual rules', (function () use ($hub, $ev) {
    [, $r] = $ev('other', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/w/shop/src/a.php']]);
    return $hub->wait('claude-code', 'other', 'permission', $r['seq'])[0] === 204;
})());

// its turn ends: the listener runs the checks; not met, it is told what is missing and goes on
$ev('g1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Stage s2 done. Next: s3.']);
$hub->jobReport('vm', $g['job'], 'exited', 0, ''); // claude -p ends with its turn
check('goal: after its turn, its listener gets the checks to run in its folder, once', $goal($g['id'])['state'] === 'checking'
    && $hub->next('vm') === [200, "check\n{$g['id']}\n/w/shop\n./tools-workflow scenario:close-dates --scenario fw\n./tools-workflow ci:status --sha HEAD"] && $hub->next('vm') === [204, '']);
check('goal: only its machine reports the results', $hub->goalReport('laptop', $g['id'], '')[0] === 404);
$hub->goalReport('vm', $g['id'], "=== 0 0\nE05 2026-11-01\n=== 1 1\nred: 2 tests failed\n");
$G = $goal($g['id']);
[$code, $resume] = $hub->next('vm');
check('goal: not met: it is resumed with what the checks show, as run 1', $G['state'] === 'working' && (int)$G['runs'] === 1 && $code === 200
    && str_starts_with($resume, "resume\n{$g['job']}\n/w/shop\ng1\nThe objective is not met yet (run 1 of 20)") && str_contains($resume, 'red: 2 tests failed') && str_contains($resume, 'exit 1'), $resume);
$hub->jobReport('vm', $g['job'], 'started', 100, '');
check('goal: the board\'s word teaches the assistant nothing', !$app->store->q("SELECT 1 FROM agent_perms WHERE key LIKE '%resume%'")->fetchColumn()
    && $app->pilot()->boardSent('claude-code:g1') === []);

// the same results 3 times: no progress, it pauses and says why
foreach (range(1, 2) as $i) {
    $ev('g1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Trying again.']);
    $hub->jobReport('vm', $g['job'], 'exited', 0, '');
    $hub->next('vm');
    $hub->goalReport('vm', $g['id'], "=== 0 0\nE05 2026-11-01\n=== 1 1\nred: 2 tests failed\n");
    if ($i === 1) { $hub->next('vm'); $hub->jobReport('vm', $g['job'], 'started', 100 + $i, ''); }
}
$G = $goal($g['id']);
check('goal: no progress (the same results 3 times): it pauses, says why, and sends nothing more', $G['state'] === 'paused' && str_contains($G['why'], 'no progress')
    && str_contains($text('g1'), 'Objective paused') && $hub->next('vm') === [204, '']);
check('goal: only a paused objective goes on', str_contains($err(fn() => $hub->goalAct($g['id'], 'forget')), 'Stop it first'));
$hub->goalAct($g['id'], 'resume');
$hub->next('vm');
$hub->goalReport('vm', $g['id'], "=== 0 0\nE05 2026-11-01\nE06c 2026-11-26\n=== 1 0\ngreen\n");
[, $resume] = $hub->next('vm');
check('goal: met (every check passes): it is asked for its report, nothing new', $goal($g['id'])['state'] === 'reporting' && str_contains($resume, 'The objective is met')
    && str_contains($resume, 'final status report') && str_contains($resume, 'Do not start new work'), $resume);
$hub->jobReport('vm', $g['job'], 'started', 200, '');
$ev('g1', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'E06c: closed. GATE_5 pass. CI green.']);
$G = array_values(array_filter($hub->board()['goals'], fn($x) => $x['id'] === $g['id']))[0];
check('goal: its report ends it: done, and the thread says so', $G['state'] === 'done' && !$G['active'] && str_contains($text('g1'), 'Objective met: Close epic E06c')
    && $G['result'][1] === ['cmd' => './tools-workflow ci:status --sha HEAD', 'expect' => '', 'code' => 0, 'ok' => true, 'out' => 'green']);
check('goal: once done, its session has the usual rules again', !$hub->inGoal('claude-code:g1') && $hub->board()['goals'][0]['id'] === $g['id']);

// 1.59.0: the assistant reads why the run stopped and writes the next one, with the objective, your guidelines, the checks
// and the workflow's command; only what is not the objective's to decide waits for you, with its proposal
$app->pilot()->setPolicy(['analyse' => true]);
$g2 = $hub->goalStart('vm', '/w/shop', 'E07', 'Close E07.', 'ls', ['git'], '', 20, 12, 'you', '/scenario-orchestrator fw', 'The spec is OK\'d: decide the implementation yourself.')['goal'];
$hub->next('vm'); $hub->jobReport('vm', $g2['job'], 'started', 300, '');
$ev('g2', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g2['job']]);
$before = Pilot::$model;
$seen = '';
$says = function (array $j) use (&$seen) { return function (string $p) use (&$seen, $j) { $seen = $p; return json_encode($j); }; };
$round = function (string $out, string $msg = '', string $crash = '') use ($hub, $ev, $g2) { // a run ends (its turn, or its process), its checks come back
    if ($msg !== '') { $ev('g2', ['hook_event_name' => 'Stop', 'last_assistant_message' => $msg]); $hub->jobReport('vm', $g2['job'], 'exited', 0, ''); }
    else $hub->jobReport('vm', $g2['job'], 'exited', 0, $crash);
    $hub->next('vm');
    $hub->goalReport('vm', $g2['id'], "=== 0 1\n$out\n");
};
Pilot::$model = $says(['situation' => 'question', 'summary' => 'It asks whether to split the migration.', 'question' => 'Split the migration?', 'resume' => 'Yes, split it in two.',
    'needs_person' => false, 'level' => 'L2', 'confidence' => 0.8]);
$round('nope 1', 'Should I split the migration in two? (Level: L2)');
check('goal: the checks are back: the reading is the worker\'s, the listener\'s poll does not wait for a model', $goal($g2['id'])['state'] === 'checked' && $hub->next('vm') === [204, '']
    && $app->pilot()->analyseNow('claude-code:g2') === 'goal' && $seen === '');
$hub->goalsTick(true);
[, $resume] = $hub->next('vm');
check('goal: the assistant read the turn with the objective, your guidelines, the checks and the command', str_contains($seen, 'Close E07.') && str_contains($seen, 'decide the implementation yourself')
    && str_contains($seen, 'Should I split the migration') && str_contains($seen, '[fails] $ ls') && str_contains($seen, '/scenario-orchestrator fw'), $seen);
check('goal: it answers the question itself (L2), then says where the objective stands and to go on with the workflow', $goal($g2['id'])['state'] === 'working' && (int)$goal($g2['id'])['runs'] === 1
    && str_contains($resume, "g2\nYes, split it in two.\n\nObjective: E07 (run 1 of 20). Checks not passing yet: `ls` (exit 1).\nThen go on with the workflow: run /scenario-orchestrator fw.")
    && str_contains($text('g2'), 'The assistant read the turn (a question): It asks whether to split the migration.'), $resume);
check('goal: the board\'s own reading leaves an objective\'s turns alone', $app->pilot()->boardSent('claude-code:g2') === [] && !$app->pilot()->boardPrediction('claude-code:g2', (int)$hub->get('claude-code:g2')['seq']));
$hub->jobReport('vm', $g2['job'], 'started', 301, '');
Pilot::$model = $says(['situation' => 'question', 'summary' => 'It asks whether to drop the pricing page.', 'question' => 'Drop the pricing page?', 'resume' => 'Keep it.',
    'needs_person' => false, 'level' => 'L3', 'confidence' => 0.9]);
$round('nope 2', 'Should we drop the pricing page from this epic? (Level: L5)');
$hub->goalsTick(true);
$G = array_values(array_filter($hub->board()['goals'], fn($x) => $x['id'] === $g2['id']))[0];
check('goal: a question at L5 (its own card, never lowered) waits for you, with the proposal, and sends nothing', $G['state'] === 'needs_you' && $G['ask']['text'] === 'Keep it.' && $G['ask']['level'] === 'L6'
    && $G['ask']['question'] === 'Drop the pricing page?' && $hub->next('vm') === [204, ''] && str_contains($text('g2'), 'This waits for you (L6)'));
check('goal: only what waits for you can be sent', str_contains($err(fn() => $hub->goalAct($g['id'], 'send')), 'Nothing'));
$hub->goalAct($g2['id'], 'send');
[, $resume] = $hub->next('vm');
check('goal: Send gives it the proposal, and it goes on', $goal($g2['id'])['state'] === 'working' && $goal($g2['id'])['ask'] === '' && str_contains($resume, "g2\nKeep it.\n\nObjective: E07 (run 2 of 20)"), $resume);
$hub->jobReport('vm', $g2['job'], 'started', 302, '');
Pilot::$model = $says(['situation' => 'error', 'summary' => 'The API was overloaded.', 'question' => '', 'resume' => 'The run was cut by an API error: redo its last step.',
    'needs_person' => false, 'level' => 'L1', 'confidence' => 0.9]);
$round('nope 3', '', 'API Error: 529 overloaded');
check('goal: a process that ended before its turn did is read too, with its output, not ended', $hub->get('claude-code:g2')['state'] === 'waiting' && $goal($g2['id'])['state'] === 'checked'
    && str_contains($text('g2'), 'Its process ended before its turn did'));
$hub->goalsTick(true);
[, $resume] = $hub->next('vm');
check('goal: and it goes on with what to do about it', str_contains($seen, 'API Error: 529 overloaded') && str_contains($resume, 'redo its last step') && $goal($g2['id'])['state'] === 'working', $resume);
$hub->jobReport('vm', $g2['job'], 'started', 303, '');
Pilot::$model = fn() => 'not JSON';
$round('nope 4', 'Stage s4 done.');
$hub->goalsTick(true);
[, $resume] = $hub->next('vm');
check('goal: no usable reading: the board\'s plain word goes (here, the workflow\'s command alone)', $resume === "resume\n{$g2['job']}\n/w/shop\ng2\n/scenario-orchestrator fw", $resume);
$hub->jobReport('vm', $g2['job'], 'started', 304, '');
$round('nope 5', 'Stage s5 done.');
$app->store->q('UPDATE agent_goals SET checked = ? WHERE id = ?', [time() - AgentHub::READ_WAIT - 1, $g2['id']]);
$hub->next('vm');
check('goal: no worker read it in time: the board\'s plain word goes on the listener\'s poll', $goal($g2['id'])['state'] === 'working' && (int)$goal($g2['id'])['runs'] === 5);
Pilot::$model = $before;
$hub->goalAct($g2['id'], 'stop');
check('goal: Stop ends the objective and its session', $goal($g2['id'])['state'] === 'stopped' && $hub->get('claude-code:g2')['state'] === 'stopped'
    && str_contains($err(fn() => $hub->goalAct($g2['id'], 'stop')), 'not running'));
check('goal: a stopped one can be forgotten', $hub->goalAct($g2['id'], 'forget') === ['ok' => true] && $goal($g2['id']) === null);

$g3 = $hub->goalStart('vm', '/w/shop', 'E08', 'x', 'ls')['goal'];
$hub->next('vm'); $hub->jobReport('vm', $g3['job'], 'failed', 0, 'claude: command not found');
$hub->board();
check('goal: a session that never started fails the objective, with why', $goal($g3['id'])['state'] === 'failed' && str_contains($goal($g3['id'])['why'], 'not found'));

// 1.58.0: each run starts with the same command, e.g. the workflow's skill, which follows its own plan
$app->pilot()->setPolicy(['analyse' => false, 'enabled' => false]);
check('goal: a command for each run is one line', str_contains($err(fn() => $hub->goalStart('vm', '/w/shop', 'E09', '', 'ls', [], '', 20, 12, 'you', "/scenario-orchestrator fw\nand more")), 'one line'));
$g4 = $hub->goalStart('vm', '/w/shop', 'E09', 'Close E09.', 'ls', [], '', 20, 12, 'you', ' /scenario-orchestrator fw ')['goal'];
[, $body] = $hub->next('vm');
check('goal: a slash command for each run is the whole first prompt, as typed', $g4['every_run'] === '/scenario-orchestrator fw' && $body === "start\n{$g4['job']}\n/w/shop\n/scenario-orchestrator fw", $body);
$hub->jobReport('vm', $g4['job'], 'started', 400, '');
$ev('g4', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g4['job']]);
$ev('g4', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Stage s1 done.']);
$hub->jobReport('vm', $g4['job'], 'exited', 0, '');
$hub->next('vm');
$hub->goalReport('vm', $g4['id'], "=== 0 1\nnope\n");
[, $resume] = $hub->next('vm');
check('goal: and every run after it the same command again, nothing else', $resume === "resume\n{$g4['job']}\n/w/shop\ng4\n/scenario-orchestrator fw" && (int)$goal($g4['id'])['runs'] === 1, $resume);
$hub->goalAct($g4['id'], 'stop');
$g5 = $hub->goalStart('vm', '/w/shop', 'E10', 'Close E10.', 'ls', [], '', 20, 12, 'you', 'Run the next stage of the plan.')['goal'];
[, $body] = $hub->next('vm');
check('goal: other text for each run comes first, the objective after it', str_starts_with($body, "start\n{$g5['job']}\n/w/shop\nRun the next stage of the plan.\n\nClose E10.") && str_contains($body, 'Objective, set on the Talixio board'), $body);


// 1.59.1: a step taken counts as progress even when the checks still show the same; a usage limit waits for its reset;
// a check that needs a text says so; your answer in the session goes on with what waited for you
$app->pilot()->setPolicy(['analyse' => false, 'enabled' => false]);
$g6 = $hub->goalStart('vm', '/w/shop', 'E11', 'Close E11.', 'ls => E11 closed', [], '', 20, 12, 'you', '')['goal'];
$hub->next('vm'); $hub->jobReport('vm', $g6['job'], 'started', 500, '');
$ev('g6', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g6['job']]);
$turn = function (string $git, string $msg = 'Stage done.', string $crash = '') use ($hub, $ev, $g6) {
    if ($crash === '') { $ev('g6', ['hook_event_name' => 'Stop', 'last_assistant_message' => $msg]); $hub->jobReport('vm', $g6['job'], 'exited', 0, ''); }
    else $hub->jobReport('vm', $g6['job'], 'exited', 0, $crash);
    $hub->next('vm');
    $hub->goalReport('vm', $g6['id'], "=== 0 0\nE11 open\n" . ($git !== '' ? "=== git\n$git\n" : ''));
    [, $r] = $hub->next('vm');
    if (str_starts_with($r, 'resume')) $hub->jobReport('vm', $g6['job'], 'started', 501, '');
    return $r;
};
$r1 = $turn('111 1'); $turn('222 2'); $turn('333 3'); $turn('444 4');
check('goal: the project changes at each step (its git state), the checks show the same: it is progress, it goes on', $goal($g6['id'])['state'] === 'working' && (int)$goal($g6['id'])['runs'] === 4
    && (int)$goal($g6['id'])['same'] === 0 && $goal($g6['id'])['result'] !== '' && !str_contains($goal($g6['id'])['result'], '=== git'));
check('goal: a check that needs a text says so, not its exit code', str_contains($r1, '$ ls → its output does not show "E11 closed"'), $r1);
$turn('444 4'); $turn('444 4');
check('goal: neither the checks nor the project change: no progress, it pauses', $goal($g6['id'])['state'] === 'paused' && str_contains($goal($g6['id'])['why'], 'the project did not change'));
$hub->goalAct($g6['id'], 'resume');
$hub->next('vm');
$hub->goalReport('vm', $g6['id'], "=== 0 0\nE11 open\n=== git\n555 5\n");
$hub->next('vm'); $hub->jobReport('vm', $g6['job'], 'started', 502, '');
$reset = time() + 7200;
$r = $turn('555 5', '', "Claude AI usage limit reached|$reset");
$G = $goal($g6['id']);
check('goal: Claude Code hit its usage limit: it waits for the reset, sends nothing, spends no run', $r === '' && $G['state'] === 'checked' && (int)$G['wait_until'] === $reset + 60
    && str_contains($G['why'], 'usage limit') && str_contains($text('g6'), 'hit its usage limit'));
$hub->goalsTick(true);
check('goal: still waiting on the next tick', $hub->next('vm') === [204, ''] && $goal($g6['id'])['state'] === 'checked');
$app->store->q('UPDATE agent_goals SET wait_until = ? WHERE id = ?', [time() - 1, $g6['id']]);
$hub->goalsTick(true);
[, $r] = $hub->next('vm');
check('goal: once it resets, it goes on', str_starts_with($r, 'resume') && $goal($g6['id'])['state'] === 'working' && $goal($g6['id'])['why'] === '', $r);
check('goal: a usage limit is read from its output, or a last message that is only that error; not from ordinary work',
    AgentHub::limitReset('', "You've hit your limit · resets 3pm") > time() + 3000 && AgentHub::limitReset('API Error: 429 rate_limit_error') <= time() + 300
    && AgentHub::limitReset('', 'Added a rate limiter; the usage limit page is done.') === null && AgentHub::limitReset('Error: ENOENT') === null);
$hub->goalAct($g6['id'], 'stop');
$app->pilot()->setPolicy(['analyse' => true, 'enabled' => true]);
$g7 = $hub->goalStart('vm', '/w/shop', 'E12', 'Close E12.', 'ls')['goal'];
$hub->next('vm'); $hub->jobReport('vm', $g7['job'], 'started', 600, '');
$ev('g7', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g7['job']]);
Pilot::$model = fn() => json_encode(['situation' => 'question', 'summary' => 'Pricing.', 'question' => 'Free plan export?', 'resume' => 'Keep it.', 'needs_person' => true, 'level' => 'L5', 'confidence' => 0.6]);
$ev('g7', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Keep export on the free plan?']); $hub->jobReport('vm', $g7['job'], 'exited', 0, '');
$hub->next('vm'); $hub->goalReport('vm', $g7['id'], "=== 0 1\nno\n"); $hub->goalsTick(true);
$was = $goal($g7['id'])['state'];
$hub->command('claude-code:g7', 'reply', 'Remove it.');
check('goal: you answer in the session what waited for you: it goes on, the card goes', $was === 'needs_you' && $goal($g7['id'])['state'] === 'working' && $goal($g7['id'])['ask'] === ''
    && str_ends_with($hub->next('vm')[1], "g7\nRemove it."));
$hub->goalAct($g7['id'], 'stop');
Pilot::$model = null;

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
