<?php
declare(strict_types=1);
// 1.55.0: the assistant answers permission requests the way you do: what only reads, and what you allowed 3 times in
// that project without ever denying it. Never a request labelled « Check first ».
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, PermCheck};

check('perm keys: a request that only reads has none; a risky one cannot be learned', PermCheck::keys('Bash', ['command' => 'ls; git status']) === []
    && PermCheck::keys('Bash', ['command' => 'git push']) === null && PermCheck::keys('Bash', ['command' => 'echo $(id)']) === null);
check('perm keys: outside a known project, each part that does more than read is learned by its first two words', PermCheck::keys('Bash', ['command' => 'cd /w && timeout 150 npx playwright test -c a.ts 2>&1 | tail -4']) === [['npx playwright']]
    && PermCheck::keys('Bash', ['command' => 'git add -A && git commit -m "x"']) === [['git add'], ['git commit']]);
check('perm keys: a file edit by its folder, a page by its host', PermCheck::keys('Edit', ['file_path' => '/w/src/a.php']) === [['Edit /w/src']]
    && PermCheck::keys('WebFetch', ['url' => 'https://Docs.example.com/x']) === [['WebFetch docs.example.com']] && PermCheck::keys('mcp__x__y', []) === null);
// 1.56.0: in its project, each part from its narrowest key to its widest
$k = fn(string $cmd) => PermCheck::keys('Bash', ['command' => $cmd], '/w/shop');
check('perm ladder: a project tool by its subcommand, then the tool, by relative or absolute path', $k('./tools-workflow scenario:query --x 1') === [['./tools-workflow scenario:query', './tools-workflow']]
    && $k('/w/shop/tools-workflow check-gate') === [['./tools-workflow check-gate', './tools-workflow']]);
check('perm ladder: a script by itself, then its folder, and cd is followed', $k('php packages/cli/tests/lib/a.php') === [['php packages/cli/tests/lib/a.php', 'php packages/cli/tests/lib/*']]
    && $k('cd packages/cli && php tests/lib/b.php') === [['php packages/cli/tests/lib/b.php', 'php packages/cli/tests/lib/*']] && $k('node /usr/lib/x.js') === [['node /usr/lib/x.js']]);
check('perm ladder: npm run by its script, then the family', $k('npm run build') === [['npm run build', 'npm run']] && $k('npm test') === [['npm test']]);
check('perm ladder: file changes inside the project are one family, never outside it', $k('mv src/a.php src/b.php') === [['mv (in project)', 'file changes (in project)']]
    && $k("sed -i 's/a/b/' f.php") === [['sed -i (in project)', 'file changes (in project)']] && $k('cp a /tmp/b') === [['cp a']]
    && $k('cd /etc && mv a b') === [['mv a']] && $k('mv a $HOME/b') === [['mv a']] && $k('tee ../x') === [['tee ../x']]);
check('perm ladder: git, npx and interpreters run without a script never widen', $k('git commit -m x') === [['git commit']] && $k('npx vitest run') === [['npx vitest']]
    && $k('php -l a.php') === [['php -l']] && $k('make test') === [['make test']] && $k('git push') === null);
check('perm ladder: an edit by its folder, its top folder, the project; never the agent\'s own folder', PermCheck::keys('Edit', ['file_path' => '/w/shop/packages/x/y.ts'], '/w/shop') === [['Edit packages/x', 'Edit packages/**', 'Edit **']]
    && PermCheck::keys('Write', ['file_path' => '/w/shop/a.md'], '/w/shop') === [['Edit .', 'Edit **']] && PermCheck::keys('Edit', ['file_path' => '/w/shop/.claude/x.md'], '/w/shop') === [['Edit .claude']]
    && PermCheck::keys('Edit', ['file_path' => '/elsewhere/a.md'], '/w/shop') === [['Edit /elsewhere']]);

$d = test_dir('agent-learn');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$hub->token();
$state = fn(string $sid) => $hub->get("claude-code:$sid")['state'];
// a permission request in a project; what the hook gets back at once (null: it waits for you)
$ask = function (string $sid, string $cmd, string $cwd = '/w/shop') use ($hub) {
    [, $r] = $hub->event(['session_id' => $sid, 'cwd' => $cwd, 'hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => $cmd]], 'laptop');
    [$code, $body] = $hub->wait('claude-code', $sid, 'permission', $r['seq']);
    return $code === 200 ? (json_decode($body, true)['hookSpecificOutput']['decision']['behavior'] ?? '') : null;
};
$you = function (string $sid, string $kind) use ($hub) { $s = $hub->get("claude-code:$sid"); $hub->command("claude-code:$sid", $kind); $hub->wait('claude-code', $sid, 'permission', (int)$s['seq']); };

check('agent learn: out of the box, the assistant learns from you', $hub->board()['perms'] === 'learn');
check('agent learn: a read-only request is allowed by the assistant, and the thread says why', $ask('a', 'head -c 300 /tmp/x.log; ls /tmp') === 'allow'
    && $state('a') === 'working' && str_contains(implode(' ', array_column($hub->thread('claude-code:a')['events'], 'text')), 'it only reads'));
check('agent learn: a new command waits for you', $ask('a', 'npx vitest run') === null);
$you('a', 'allow');
$ask('a', 'npx vitest run src/x.test.ts'); $you('a', 'allow');
check('agent learn: not before the third time', $ask('a', 'npx vitest run --watch=false') === null);
$you('a', 'allow');
check('agent learn: after you allowed it 3 times, the assistant allows it', $ask('a', 'npx vitest run -t cart 2>&1 | tail -20') === 'allow'
    && str_contains(implode(' ', array_column($hub->thread('claude-code:a')['events'], 'text')), 'you allowed this 3 times'));
check('agent learn: the assistant\'s own answers do not count as yours', (int)$app->store->q("SELECT allowed FROM agent_perms WHERE key = 'npx vitest'")->fetchColumn() === 3);
check('agent learn: only in that project', $ask('b', 'npx vitest run', '/w/other') === null);
$you('b', 'deny');
check('agent learn: chained with a part not learned, it waits', $ask('a', 'npx vitest run && npm run build') === null);
$you('a', 'allow');
foreach (range(1, 2) as $i) { $ask('c', 'git commit -m x'); $you('c', 'allow'); }
$ask('c', 'git commit -m y'); $you('c', 'deny');
$waited = 0;
foreach (range(1, 3) as $i) { $waited += $ask('c', 'git commit -m z') === null ? 1 : 0; $you('c', 'allow'); }
check('agent learn: once you denied it, it is yours again, however often you allow it after', $waited === 3 && $ask('c', 'git commit -m w') === null);
$you('c', 'allow');
foreach (range(1, 4) as $i) { $ask('c', 'git push origin main'); if ($state('c') === 'permission') $you('c', 'allow'); }
check('agent learn: « Check first » is never learned', $ask('c', 'git push origin main') === null && $state('c') === 'permission');
$you('c', 'deny');
// 1.56.0: a wider key, once allowed 3 times in 2 different forms
$ask('d', './tools-workflow scenario:query'); $you('d', 'allow');
$ask('d', './tools-workflow scenario:query --all'); $you('d', 'allow');
check('agent learn: one form allowed 3 times does not widen', $ask('d', './tools-workflow check-gate') === null);
$you('d', 'allow');
check('agent learn: 3 allows in 2 forms widen to the whole tool, and the thread names it', $ask('d', '/w/shop/tools-workflow scenario:decision:record x') === 'allow'
    && str_contains(implode(' ', array_column($hub->thread('claude-code:d')['events'], 'text')), 'requests like this one (./tools-workflow)'));
check('agent learn: never to a risky subcommand', $ask('d', './tools-workflow deploy:dev') === null);
$you('d', 'deny');
// an edit request in a project: true when the assistant allowed it at once; your answer, if it waits
$edit = function (string $sid, string $cwd, string $path, string $answer = 'allow') use ($hub, $you) {
    [, $r] = $hub->event(['session_id' => $sid, 'cwd' => $cwd, 'hook_event_name' => 'PermissionRequest', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => $path]], 'laptop');
    [$code] = $hub->wait('claude-code', $sid, 'permission', $r['seq']);
    if ($code !== 200) $you($sid, $answer);
    return $code === 200;
};
foreach (['src/a', 'src/a', 'lib'] as $dir) $edit('e', '/w/shop', "/w/shop/$dir/x.php");
check('agent learn: edits in 2 folders widen to the project, never to the agent\'s own folder or outside', $edit('e', '/w/shop', '/w/shop/docs/new.md')
    && !$edit('e', '/w/shop', '/w/shop/.claude/agents/x.md', 'deny') && !$edit('e', '/w/shop', '/elsewhere/a.md'));
foreach (['src/a', 'src/a', 'src/a'] as $dir) $edit('f', '/w/deny', "/w/deny/$dir/x.php");
$edit('f', '/w/deny', '/w/deny/lib/x.php', 'deny');
check('agent learn: after a denial the widening stops, what was learned narrower stays', !$edit('f', '/w/deny', '/w/deny/tests/t.php') && $edit('f', '/w/deny', '/w/deny/src/a/y.php'));
$hub->setPerms('read');
check('agent learn: « allows read-only »: read-only yes, learned no', $ask('a', 'ls') === 'allow' && $ask('a', 'npx vitest run') === null);
$you('a', 'allow');
$hub->setPerms('off');
check('agent learn: « Always ask me »: everything waits for you', $ask('a', 'ls') === null);
$you('a', 'allow');
$err = ''; try { $hub->setPerms('all'); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
check('agent learn: an unknown choice is refused', $err !== '' && $hub->permMode() === 'off');

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
