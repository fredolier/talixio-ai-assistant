<?php
declare(strict_types=1);
// 1.48.0: connecting a machine in one command, curl -fsSL '<address>/api/agents/install?c=<code>' | sh. Before, the
// person saved the script and merged the hooks into ~/.claude/settings.json by hand, which went wrong easily.
// The real command is run here, against a real server, in a home folder of its own.
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App};

$d = test_dir('agent-install');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS', 'TALIXIO_PUBLIC_URL'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
$dw = "$d/w"; @mkdir($dw, 0777, true);
$web = web_app($dw, ['TALIXIO_WEB_PASSWORD' => 'pw']);
$port = (int)(new ReflectionFunction($web))->getStaticVariables()['port'];
putenv("TALIXIO_DATA_DIR=$dw"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
putenv("TALIXIO_PUBLIC_URL=http://127.0.0.1:$port"); // the server learns it from the request
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();

$web('/api/login', ['password' => 'pw']);
$r = $web('/api/agents/install-code', ['machine' => "lap'top", 'listener' => false]);
check('agent install: the board gives a one-line command with a one-time code, not the token', ($r['_code'] ?? 0) === 200
    && preg_match("#^curl -fsSL 'http://127\\.0\\.0\\.1:$port/api/agents/install\\?c=[\\w-]{24}' \\| sh$#", (string)($r['command'] ?? ''))
    && !str_contains($r['command'], $hub->token()) && $r['expires'] > time() + 800, $r['_raw']);
$sh = function (string $cmd, string $home, array $env = []) {
    $p = proc_open(['sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null,
        $env + ['HOME' => $home, 'PATH' => getenv('PATH')]);
    $o = stream_get_contents($pp[1]); $e = stream_get_contents($pp[2]);
    return [proc_close($p), $o, $e];
};
$ours = fn(array $s) => array_sum(array_map(fn($ev) => count(array_filter(array_merge(...array_map(fn($g) => $g['hooks'] ?? [], $ev)),
    fn($h) => str_contains($h['command'] ?? '', '.talixio/agent-hook.sh'))), $s['hooks'] ?? []));

// a machine with settings of its own: another hook, an old Talixio hook, other keys
$home = "$d/home1"; @mkdir("$home/.claude", 0777, true);
$mine = ['model' => 'opus', 'permissions' => ['allow' => ['Bash(npm test)']], 'hooks' => [
    'Stop' => [['hooks' => [['type' => 'command', 'command' => 'say done']]], ['hooks' => [['type' => 'command', 'command' => 'sh ~/.talixio/agent-hook.sh reply', 'timeout' => 9]]]],
    'PostToolUse' => [['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => 'prettier --write']]]]]];
file_put_contents("$home/.claude/settings.json", json_encode($mine, JSON_PRETTY_PRINT));
[$rc, $o, $e] = $sh($r['command'], $home);
$s = json_decode((string)@file_get_contents("$home/.claude/settings.json"), true) ?: [];
check('agent install: the command installs the hook script, as the board would give it', $rc === 0 && is_file("$home/.talixio/agent-hook.sh")
    && file_get_contents("$home/.talixio/agent-hook.sh") === rtrim($hub->script("lap'top"), "\n") . "\n" && (fileperms("$home/.talixio/agent-hook.sh") & 0777) === 0700, "$rc $o $e");
check('agent install: the hooks are merged: yours stay, the old Talixio one is replaced, each event has ours once',
    $s['model'] === 'opus' && $s['permissions'] === $mine['permissions'] && $s['hooks']['PostToolUse'] === $mine['hooks']['PostToolUse']
    && $s['hooks']['Stop'][0]['hooks'][0]['command'] === 'say done' && count($s['hooks']['Stop']) === 2
    && $ours($s) === count(AgentHub::claudeSettings()['hooks']) && !is_file("$home/.talixio/agent-listen.sh"), json_encode($s));
check('agent install: your settings were backed up first, and it says so', json_decode(file_get_contents("$home/.claude/settings.json.talixio-backup"), true) === $mine
    && str_contains($o, 'settings.json.talixio-backup') && str_contains($o, 'Start a new Claude Code session'), $o);
[$rc, , $e] = $sh($r['command'], $home);
check('agent install: the code works once: again, it says so and changes nothing', $rc !== 0 && str_contains($e, 'already used or has expired')
    && json_decode(file_get_contents("$home/.claude/settings.json"), true) === $s, $e);

// running a new command again replaces ours, with each merge tool; the listener when asked
foreach (['python3', 'node', 'jq'] as $tool) {
    $c = $hub->installCode('box', true)['command'];
    [$rc, $o, $e] = $sh($c, $home, ['TALIXIO_MERGE' => $tool]);
    $s2 = json_decode((string)file_get_contents("$home/.claude/settings.json"), true) ?: [];
    check("agent install: with $tool, a second install keeps one set of Talixio hooks and your own", $rc === 0 && $ours($s2) === count(AgentHub::claudeSettings()['hooks'])
        && $s2['hooks']['PostToolUse'] === $mine['hooks']['PostToolUse'] && $s2['hooks']['Stop'][0]['hooks'][0]['command'] === 'say done' && $s2['model'] === 'opus'
        && str_contains($o, "merged with $tool"), "$rc $o $e " . json_encode($s2));
}
check('agent install: the listener is installed when asked, and the machine name is the one given', is_file("$home/.talixio/agent-listen.sh")
    && str_contains(file_get_contents("$home/.talixio/agent-listen.sh"), "M='box'") && str_contains(file_get_contents("$home/.talixio/agent-hook.sh"), "M='box'"));

// a machine with no settings yet, and one whose settings are not JSON
$home2 = "$d/home2"; @mkdir($home2);
[$rc, , $e] = $sh($hub->installCode('fresh', false)['command'], $home2);
check('agent install: without settings, they are created with the hooks', $rc === 0 && $ours(json_decode((string)@file_get_contents("$home2/.claude/settings.json"), true) ?: []) === count(AgentHub::claudeSettings()['hooks']), $e);
$home3 = "$d/home3"; @mkdir("$home3/.claude", 0777, true); file_put_contents("$home3/.claude/settings.json", "{ \"model\": \"opus\", // a comment\n}");
foreach (['python3', 'node', 'jq'] as $tool) {
    [$rc, , $e] = $sh($hub->installCode('x', false)['command'], $home3, ['TALIXIO_MERGE' => $tool]);
    check("agent install: with $tool, settings that are not JSON are left as they were, and it says why", $rc !== 0 && str_contains($e, 'could not be read as JSON')
        && file_get_contents("$home3/.claude/settings.json") === "{ \"model\": \"opus\", // a comment\n}" && !is_file("$home3/.claude/settings.json.talixio-new"), $e);
}
[$rc, , $e] = $sh($hub->installCode('x', false)['command'], $home3, ['TALIXIO_MERGE' => 'none']);
check('agent install: with no tool to merge, the script is kept and the hooks to add are shown', $rc !== 0 && str_contains($e, 'by hand') && str_contains($e, 'PermissionRequest') && is_file("$home3/.talixio/agent-hook.sh"), $e);

// the code: expired, wrong, signed-in only to make one
$c = $hub->installCode('late', false);
$codes = json_decode($app->store->get('agents.install'), true); foreach ($codes as &$x) $x['exp'] = time() - 1; unset($x);
$app->store->set('agents.install', json_encode($codes));
check('agent install: an expired code gets nothing', $hub->installScript($c['code']) === null && $hub->installScript('') === null && $hub->installScript('nope') === null);
check('agent install: only the code\'s hash is kept', !str_contains($app->store->get('agents.install') . json_encode($codes), $c['code']));
$ch = curl_init("http://127.0.0.1:$port/api/agents/install?c=nope"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $body = (string)curl_exec($ch);
check('agent install: a wrong code gets a script that only says so (served, so that curl -f shows it)', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && str_contains($body, 'already used') && str_contains($body, 'exit 1') && !str_contains($body, $hub->token()));
check('agent install: making a code needs to be signed in', $web('/api/agents/install-code', ['machine' => 'x'], 'other')['_code'] === 401);

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
