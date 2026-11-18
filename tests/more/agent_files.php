<?php
declare(strict_types=1);
// 1.47.0: a coding agent sends a file it made (an HTML report, a PDF, a picture) to the board. It lands in the library
// (/Agents/<project>), shows in the session's thread with Open and Download, and the assistant sees it in the thread.
// The hook script's own "send" mode is run for real against a real server.
require_once __DIR__ . '/../helpers.php';
use Talixio\{AgentHub, App};

$d = test_dir('agent-files');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub();
$tmp = function (string $bytes) use ($d) { $f = tempnam($d, 'up'); file_put_contents($f, $bytes); return $f; };

[, $r] = $hub->event(['session_id' => 's1', 'hook_event_name' => 'SessionStart', 'cwd' => '/home/me/shop'], 'laptop');
[, $r] = $hub->event(['session_id' => 's1', 'hook_event_name' => 'Stop', 'cwd' => '/home/me/shop', 'last_assistant_message' => 'Report ready.'], 'laptop');
$seq = $r['seq'];

check('agent files: a file for an unknown session is refused', $hub->receiveFile('claude-code', 'nope', 'laptop', 'a.html', $tmp('<p>x</p>'))[0] === 404);
check('agent files: an empty file is refused', $hub->receiveFile('claude-code', 's1', 'laptop', 'a.html', $tmp(''))[0] === 400);
check('agent files: a file sent from another machine than the session\'s is refused', $hub->receiveFile('claude-code', 's1', 'desktop', 'a.html', $tmp('<p>x</p>'))[0] === 403);

$html = "<!doctype html><h1>Sales</h1><script>alert(1)</script>";
[$code, $out] = $hub->receiveFile('claude-code', 's1', 'laptop', 'report.html', $tmp($html));
$f = $app->files()->get((int)($out['file'] ?? 0));
check('agent files: the file is kept in the library, in /Agents/<project>, word for word', $code === 200 && $f && $f['folder'] === '/Agents/shop'
    && $f['name'] === 'report.html' && file_get_contents($f['path']) === $html, json_encode($out));
$ev = end($hub->thread('claude-code:s1')['events']);
$j = json_decode($ev['text'], true);
check('agent files: the thread shows it, from the agent', $ev['who'] === 'agent' && $ev['kind'] === 'file' && $j['file'] === (int)$f['id'] && $j['name'] === 'report.html', json_encode($ev));
check('agent files: it does not move the session on (a hook waiting for a reply keeps waiting)', $hub->get('claude-code:s1')['seq'] == $seq && $hub->wait('claude-code', 's1', 'reply', $seq)[0] === 204);
$tt = $hub->threadText('claude-code:s1');
check('agent files: the assistant sees the file in the thread, with its id for file_read', str_contains($tt, 'report.html') && str_contains($tt, 'file #' . $f['id']), $tt);
[, $out2] = $hub->receiveFile('claude-code', 's1', 'laptop', 'report.html', $tmp('<p>v2</p>'));
check('agent files: the same name again is kept beside the first, not over it', $app->files()->get((int)$out2['file'])['name'] === 'report (2).html'
    && file_get_contents($f['path']) === $html);
[, $out3] = $hub->receiveFile('claude-code', 's1', 'laptop', '../../etc/passwd', $tmp('x'));
$f3 = $app->files()->get((int)$out3['file']);
check('agent files: a name with a path keeps only the name', $f3['folder'] === '/Agents/shop' && !str_contains($f3['name'], '/'), json_encode($f3));

// for real: the hook script's send mode, against a real server
$dw = "$d/w"; @mkdir($dw, 0777, true);
putenv("TALIXIO_DATA_DIR=$dw");
$wa = App::fromEnv(); $wa->cfg->notify = [];
$whub = $wa->agentHub();
$web = web_app($dw, ['TALIXIO_WEB_PASSWORD' => 'pw']);
$port = (int)(new ReflectionFunction($web))->getStaticVariables()['port'];
$sh = preg_replace("/^U=.*$/m", "U='http://127.0.0.1:$port'", $whub->script('laptop'));
$hookF = "$d/agent-hook.sh"; file_put_contents($hookF, $sh);
$runSh = function (array $args, string $stdin = '') use ($hookF) {
    $p = proc_open(['sh', $hookF, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    fwrite($pp[0], $stdin); fclose($pp[0]);
    $o = stream_get_contents($pp[1]); $e = stream_get_contents($pp[2]);
    return [proc_close($p), $o, $e];
};
[$rc, $o] = $runSh(['event'], json_encode(['session_id' => 'w1', 'hook_event_name' => 'SessionStart', 'cwd' => '/work/site']));
check('agent files: at session start, the hook tells the agent how to send a file, with its session id', $rc === 0 && str_contains($o, "$hookF send <file> w1"), $o);
[$rc, $o] = $runSh(['event'], json_encode(['session_id' => 'w1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => 'hi', 'cwd' => '/work/site']));
check('agent files: other events print nothing (their output would reach the agent)', $rc === 0 && $o === '', $o);
$pdf = "$d/Q3 résumé.pdf"; file_put_contents($pdf, "%PDF-1.4\n% test\n");
[$rc, $o, $e] = $runSh(['send', $pdf, 'w1']);
$sent = array_values(array_filter($wa->agentHub()->thread('claude-code:w1')['events'], fn($x) => $x['kind'] === 'file'));
$wf = $sent ? $wa->files()->get((int)json_decode($sent[0]['text'], true)['file']) : null;
check('agent files: "send" uploads the file, name and bytes kept, and says where it went', $rc === 0 && $wf && $wf['name'] === 'Q3 résumé.pdf'
    && $wf['folder'] === '/Agents/site' && file_get_contents($wf['path']) === file_get_contents($pdf) && str_contains($o, 'Q3 résumé.pdf'), "$rc $o $e");
[$rc, , $e] = $runSh(['send', "$d/missing.html", 'w1']);
check('agent files: a missing file is said, with a failing exit code', $rc !== 0 && str_contains($e, 'missing.html'), $e);
[$rc, , $e] = $runSh(['send', $pdf]);
check('agent files: without a session id it says what to give', $rc !== 0 && str_contains($e, 'session'), $e);
[$rc, , $e] = $runSh(['send', $pdf, 'nope']);
check('agent files: a refusal from Talixio is passed on, with a failing exit code', $rc !== 0 && str_contains($e, 'no such agent session'), $e);
$big = "$d/big.bin"; $h = fopen($big, 'w'); ftruncate($h, AgentHub::MAX_FILE + 1); fclose($h);
[$rc, , $e] = $runSh(['send', $big, 'w1']);
check('agent files: a file over the limit is refused, and nothing is kept', $rc !== 0 && str_contains($e, 'MB') && count(array_filter($wa->agentHub()->thread('claude-code:w1')['events'], fn($x) => $x['kind'] === 'file')) === 1, $e);
$ch = curl_init("http://127.0.0.1:$port/api/agents/file?agent=claude-code&session=w1"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'x', CURLOPT_HTTPHEADER => ['X-Talixio-Name: a.txt', 'X-Talixio-Machine: laptop']]);
curl_exec($ch);
check('agent files: without the agents token, nothing is accepted', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401);
// the page: the file shows in the thread, and opens on its viewer (sandboxed for HTML)
$web('/api/login', ['password' => 'pw']);
$t = $web('/api/agents/s/' . rawurlencode('claude-code:w1') . '/thread');
check('agent files: the page gets the file in the thread', ($fe = array_values(array_filter($t['events'] ?? [], fn($x) => $x['kind'] === 'file'))) && json_decode($fe[0]['text'], true)['name'] === 'Q3 résumé.pdf', $t['_raw']);

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
