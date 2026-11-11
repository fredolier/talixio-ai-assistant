<?php
declare(strict_types=1);
// The orchestrator's single typed call per pass (POST /api/conductor/tick): validation, acks, instructions, and the trace of every pass.
require_once __DIR__ . '/../helpers.php';
use Talixio\App;

foreach (['TALIXIO_DATA_DIR' => test_dir('tick'), 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_PUBLIC_URL' => 'https://me.test'] as $k => $v) putenv("$k=$v");
$app = App::fromEnv(); $con = $app->conductor();
$setup = $con->setup(); $pairCode = preg_match('/"code":"([a-f0-9]{32})"/', $setup, $m) ? $m[1] : '';
check('tick: the setup text announces the single call', str_contains($setup, 'ONE CALL PER PASS') && str_contains($setup, 'https://me.test/api/conductor/tick'));
[, $r] = $con->inbox(['type' => 'pair', 'code' => $pairCode, 'mode' => 'poll', 'session' => 's1'], '');
$sec = (string)$r['secret'];
$free = function () use ($con) { $rp = new ReflectionProperty($con, 'app'); $app = $rp->getValue($con); $f = $app->cfg->dataDir . '/conductor.json'; $b = json_decode(file_get_contents($f), true); $b['last_tick'] = 0; file_put_contents($f, json_encode($b)); };

check('tick: without the secret it is refused', $con->tick([], 'nope')[0] === 401);
$full = ['id' => 'dev-a', 'name' => 'Dev A', 'state' => 'waiting', 'ts' => time(), 'waiting' => ['kind' => 'decision', 'type' => 'L2', 'question' => 'Which migration strategy should I use for the orders table?', 'options' => ['A', 'B'], 'cost' => 'a day lost']];
$thin = ['id' => 'dev-b', 'name' => 'Dev B', 'state' => 'waiting', 'ts' => time()];
[$c, $out] = $con->tick(['agents' => [$full, $thin, ['id' => 'dev-c', 'name' => 'Dev C', 'state' => 'waiting', 'ts' => time(), 'waiting' => 'full text not retrieved yet']], 'note' => 'first pass'], $sec);
check('tick: a roster is stored and the question that is waiting is listed', $c === 200 && count($out['waiting']) === 2 && $out['next_in'] === 600);
check('tick: a waiting session without a question, and an incomplete question, come back as problems', count($out['problems']) === 2 && str_contains(implode(' ', $out['problems']), 'Dev B') && str_contains(implode(' ', $out['problems']), 'Dev C'));
check('tick: a second call within seconds is refused', $con->tick([], $sec)[0] === 429);
$free();
$con->toFleet('Use strategy A', 0, '', '');
[, $out] = $con->tick(['agents' => [$full], 'reports' => [['agent' => 'dev-a', 'kind' => 'answer', 'text' => 'Started on A.']]], $sec);
check('tick: the instructions waiting for the orchestrator are returned', count($out['instructions']) === 1 && str_contains($out['instructions'][0]['instruction'] ?? '', 'strategy A'));
check('tick: a report with an agent lands in that agent\'s thread', str_contains(json_encode($con->agentThread('dev-a')), 'Started on A.'));
$th = $con->agentThread('dev-a');
check('tick: each return in an agent thread carries its kind', end($th)['kind'] === 'answer');
$free(); $con->tick(['reports' => [['agent' => 'dev-a', 'kind' => 'status', 'text' => 'Tests green.'], ['agent' => 'dev-a', 'kind' => 'alert', 'text' => 'Disk almost full.']]], $sec);
$th = $con->agentThread('dev-a');
check('tick: status and alert returns are told apart', $th[count($th) - 2]['kind'] === 'status' && $th[count($th) - 1]['kind'] === 'alert');
$free(); $iid = $out['instructions'][0]['iid'];
[, $again] = $con->tick(['ack' => [$iid]], $sec);
check('tick: an acknowledged instruction is not handed out again', $again['instructions'] === [] && $con->statusInfo()['queued'] === 0);
$free(); $con->tick(['agents' => [$full]], $sec);
$ticks = $con->statusInfo()['ticks'];
check('tick: every pass is traced, the quiet ones too, newest first', count($ticks) >= 4 && $ticks[0]['via'] === 'tick' && $ticks[0]['reports'] === 0 && $ticks[0]['waiting'] === 1 && end($ticks)['note'] === 'first pass');
[$c] = $con->inbox(['type' => 'report', 'text' => 'old style', 'title' => 'x'], $sec);
check('tick: the older report call still works and is traced as a report', $c === 200 && $con->statusInfo()['ticks'][0]['via'] === 'report');
tests_done();
