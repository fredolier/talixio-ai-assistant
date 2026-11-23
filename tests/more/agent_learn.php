<?php
declare(strict_types=1);
// 1.55.0: the assistant answers permission requests the way you do: what only reads, and what you allowed 3 times in
// that project without ever denying it. Never a request labelled « Check first ».
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, PermCheck};

check('perm keys: a request that only reads has none; a risky one cannot be learned', PermCheck::keys('Bash', ['command' => 'ls; git status']) === []
    && PermCheck::keys('Bash', ['command' => 'git push']) === null && PermCheck::keys('Bash', ['command' => 'echo $(id)']) === null);
check('perm keys: each part that does more than read, by its first two words', PermCheck::keys('Bash', ['command' => 'cd /w && timeout 150 npx playwright test -c a.ts 2>&1 | tail -4']) === ['npx playwright']
    && PermCheck::keys('Bash', ['command' => 'git add -A && git commit -m "x"']) === ['git add', 'git commit']);
check('perm keys: a file edit by its folder, a page by its host', PermCheck::keys('Edit', ['file_path' => '/w/src/a.php']) === ['Edit /w/src']
    && PermCheck::keys('WebFetch', ['url' => 'https://Docs.example.com/x']) === ['WebFetch docs.example.com'] && PermCheck::keys('mcp__x__y', []) === null);

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
