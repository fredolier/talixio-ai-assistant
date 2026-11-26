<?php
declare(strict_types=1);
// 1.57.0: an objective. The board starts a session in a project and keeps it going until the checks you gave pass (the
// workflow's own commands, run by the listener), then asks it for its report. Its permission requests follow the rules
// set when it started, so it does not wait for you; it stops when it makes no progress, or runs out of runs or time.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Pilot};

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
    && $G['result'][1] === ['cmd' => './tools-workflow ci:status --sha HEAD', 'code' => 0, 'ok' => true, 'out' => 'green']);
check('goal: once done, its session has the usual rules again', !$hub->inGoal('claude-code:g1') && $hub->board()['goals'][0]['id'] === $g['id']);

// the assistant reads the turns: what it proposes you waits for you; a next step it would have proposed is the objective's
$app->pilot()->setPolicy(['analyse' => true]);
$g2 = $hub->goalStart('vm', '/w/shop', 'E07', 'Close E07.', 'ls')['goal'];
$hub->next('vm'); $hub->jobReport('vm', $g2['job'], 'started', 300, '');
$ev('g2', ['hook_event_name' => 'SessionStart', 'talixio_job' => $g2['job']]);
$before = Pilot::$model;
Pilot::$model = fn() => '{"kind":"next","answer":"Go on with s3.","level":"L1","confidence":0.99}';
$ev('g2', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Stage s2 done. Next: s3.']);
$hub->jobReport('vm', $g2['job'], 'exited', 0, '');
$hub->next('vm');
$hub->goalReport('vm', $g2['id'], "=== 0 1\nnope\n");
check('goal: the checks came back before the assistant read the turn: it waits for it', $goal($g2['id'])['state'] === 'checked');
$app->pilot()->analyseNow('claude-code:g2');
$hub->goalsTick();
check('goal: a next step is not yours to choose in an objective: it goes on', $goal($g2['id'])['state'] === 'working' && (int)$goal($g2['id'])['runs'] === 1);
$hub->next('vm'); $hub->jobReport('vm', $g2['job'], 'started', 301, '');
Pilot::$model = fn() => '{"kind":"answer","answer":"Drop the pricing page.","level":"L5","confidence":0.6}';
$ev('g2', ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Should we drop the pricing page from this epic? (Level: L5)']);
$hub->jobReport('vm', $g2['job'], 'exited', 0, '');
$app->pilot()->analyseNow('claude-code:g2');
$hub->next('vm');
$hub->goalReport('vm', $g2['id'], "=== 0 1\nnope\n");
check('goal: a question only you answer: it waits for you, and sends nothing', $goal($g2['id'])['state'] === 'needs_you' && $hub->next('vm') === [204, '']);
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

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
