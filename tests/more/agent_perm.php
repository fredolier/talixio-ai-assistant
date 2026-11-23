<?php
declare(strict_types=1);
// 1.53.0: a permission request on the board says what it does (read-only, or what to check), and « Always allow » sends
// Claude Code's own suggested rule back through the hook, once checked narrow, so it stops asking for it.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, PermCheck};

// the label, from the requests seen in real sessions
$lvl = fn(string $c) => implode(':', array_filter(PermCheck::bash($c)));
check('perm check: reading files outside the project is read-only', $lvl('head -c 3000 /tmp/e06b-a11/ask-base.jsonl; echo; head -c 600 /tmp/e06b-a11/ask-base.err; ls /tmp/e06b-a11') === 'read');
check('perm check: read-only git and pipes with 2>&1 are read-only', $lvl('git diff HEAD~1 -- src 2>&1 | head -50') === 'read' && $lvl('git status --short && git log --oneline -5') === 'read');
check('perm check: a | inside quotes is not a pipe', $lvl("grep -n 'tests/lib\\|tests/tools' a.php") === 'read');
check('perm check: a script in /tmp is flagged', $lvl('/tmp/e06b-a11/run.sh ask base') === 'risk:script' && $lvl('sh /tmp/x.sh') === 'risk:script');
check('perm check: a push hidden in a chain is flagged', $lvl('git status && git push origin main') === 'risk:git');
check('perm check: deletions, network, installs, deploys are flagged', $lvl('rm -rf build') === 'risk:delete' && $lvl('find . -name x -delete') === 'risk:delete'
    && $lvl('curl https://example.com') === 'risk:network' && $lvl('npm install left-pad') === 'risk:install' && $lvl('./tools-workflow deploy:dev') === 'risk:deploy');
check('perm check: code the card cannot show is flagged', $lvl('echo $(rm -rf /)') === 'risk:hidden' && $lvl('bash -c "ls"') === 'risk:hidden' && $lvl('ls `pwd`') === 'risk:hidden');
check('perm check: tests, sed -i and redirections are your call, unlabelled', $lvl('timeout 150 npx playwright test -c p.ts 2>&1 | tail -12') === ''
    && $lvl("sed -i 's/a/b/' f.php") === '' && $lvl('cat a > b') === '' && $lvl('npx vitest run deploy.test.ts') === '');
check('perm check: the agent editing its own settings is flagged', PermCheck::assess('Edit', ['file_path' => '/p/.claude/settings.local.json']) === ['level' => 'risk', 'why' => 'settings']
    && PermCheck::assess('Edit', ['file_path' => '/p/.claude/talixio-code/web-ui/a.ts'])['level'] === '' && PermCheck::assess('Read', ['file_path' => '/etc/hosts'])['level'] === 'read');

// which suggested rules may be saved
$sug = fn(string $tool, ?string $c, string $type = 'addRules') => ['type' => $type, 'behavior' => 'allow', 'destination' => 'localSettings', 'rules' => [['toolName' => $tool] + ($c === null ? [] : ['ruleContent' => $c])]];
$shown = fn(array $s) => array_map([PermCheck::class, 'show'], PermCheck::rules($s));
check('perm rules: a narrow rule is kept', $shown([$sug('Bash', 'npx playwright test:*'), $sug('Bash', 'sha256sum:*')]) === ['Bash(npx playwright test:*)', 'Bash(sha256sum:*)']);
check('perm rules: a whole tool, a lone interpreter or a bare runner is never saved', $shown([$sug('Bash', null), $sug('Bash', 'php:*'), $sug('Bash', 'npm run:*'), $sug('Bash', 'sh *'), $sug('Bash', '*')]) === []);
check('perm rules: a risky command, the agent\'s settings, other kinds of update are never saved', $shown([$sug('Bash', 'git push:*'), $sug('Bash', 'rm -rf:*'),
    $sug('Edit', '.claude/settings.json'), $sug('mcp__x__y', null), ['type' => 'setMode', 'mode' => 'bypassPermissions', 'destination' => 'localSettings'],
    ['type' => 'addDirectories', 'directories' => ['/'], 'destination' => 'localSettings']]) === []);

// on the board, through the hook
$d = test_dir('agent-perm');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$hub->token();
$sess = fn(string $id) => array_values(array_filter($hub->board()['sessions'], fn($s) => $s['id'] === $id))[0] ?? null;
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], 'laptop');

$ev('p1', ['hook_event_name' => 'SessionStart']);
[, $r] = $ev('p1', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'npx playwright test --list 2>&1 | tail -4'],
    'permission_suggestions' => [$sug('Bash', 'npx playwright test:*'), $sug('Bash', 'tail:*')]]);
$s = $sess('claude-code:p1');
check('agent perm: the board shows the rules it could save', $s['rules'] === ['Bash(npx playwright test:*)', 'Bash(tail:*)'] && $s['safety'] === '', json_encode($s));
$hub->command('claude-code:p1', 'always');
[$code, $body] = $hub->wait('claude-code', 'p1', 'permission', $r['seq']);
$dec = json_decode($body, true)['hookSpecificOutput']['decision'] ?? [];
check('agent perm: « Always allow » allows, and hands Claude Code the rules to save in the project', $code === 200 && $dec['behavior'] === 'allow'
    && $dec['updatedPermissions'] === [['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'npx playwright test:*'], ['toolName' => 'Bash', 'ruleContent' => 'tail:*']],
        'behavior' => 'allow', 'destination' => 'localSettings']], $body);
check('agent perm: the thread says what was saved', str_contains(implode(' ', array_column($hub->thread('claude-code:p1')['events'], 'text')), 'Always allowed in this project'));

[, $r] = $ev('p1', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls /tmp/x'], 'permission_suggestions' => [$sug('Bash', 'ls:*')]]);
check('agent perm: a read-only request says so', $sess('claude-code:p1')['safety'] === 'read');
$hub->command('claude-code:p1', 'allow');
$dec = json_decode($hub->wait('claude-code', 'p1', 'permission', $r['seq'])[1], true)['hookSpecificOutput']['decision'];
check('agent perm: a plain « Allow » saves nothing', $dec === ['behavior' => 'allow']);

[, $r] = $ev('p1', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin main'], 'permission_suggestions' => [$sug('Bash', 'git push:*')]]);
$s = $sess('claude-code:p1');
check('agent perm: a push is flagged, and offers no rule', $s['safety'] === 'risk:git' && $s['rules'] === []);
$err = ''; try { $hub->command('claude-code:p1', 'always'); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
check('agent perm: « Always allow » is refused when there is no rule to save', $err !== '' && $sess('claude-code:p1')['pending'] === 0);

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
