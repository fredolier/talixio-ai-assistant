<?php
declare(strict_types=1);
// Audit Q18: what only grew is cleaned up daily, costs are kept (folded per day), the often-read columns are indexed,
// and archiving old jobs is one query.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Store};

$d = test_dir('prune');
$s = new Store("$d/t.db");
$now = time(); $day = 86400;
$s->q('INSERT INTO tool_log(ts, tool, args, result) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [$now - 100 * $day, 'kb_search', '{}', 'old', $now - $day, 'kb_search', '{}', 'recent']);
$s->q('INSERT INTO tool_log(ts, tool, args, result, job_id) VALUES (?, ?, ?, ?, ?)', [$now - 400 * $day, 'fetch_url', '{}', 'a job step', 7]);
$s->q("INSERT INTO relay_messages(ts, sender, recipient, body, delivered) VALUES (?, 'a', 'b', 'old delivered', 1), (?, 'a', 'b', 'old waiting', NULL)", [$now - 40 * $day, $now - 40 * $day]);
foreach ([[$now - 200 * $day, 3, 0.5], [$now - 200 * $day + 3600, 7, 0.25], [$now - 200 * $day + 7200, 1, null], [$now - $day, 2, 0.1]] as [$ts, $in, $cost])
    $s->q("INSERT INTO usage(ts, model, input_tokens, output_tokens, cost_usd, source, tier) VALUES (?, 'claude-x', ?, 1, ?, 'chat', 'standard')", [$ts, $in, $cost]);
$total = $s->spentSince(0); $calls = (int)$s->q('SELECT COUNT(*) FROM usage')->fetchColumn();
$n = $s->prune($now, 90);
check('prune: the main thread\'s old tool log goes, recent and job steps stay', array_column($s->q('SELECT result FROM tool_log ORDER BY id')->fetchAll(), 'result') === ['recent', 'a job step'], json_encode($n));
check('prune: delivered messages between agents go after a month, waiting ones stay', array_column($s->q('SELECT body FROM relay_messages')->fetchAll(), 'body') === ['old waiting']);
check('prune: old usage is folded per day, its costs and tokens kept', (int)$s->q('SELECT COUNT(*) FROM usage')->fetchColumn() === 2 && abs($s->spentSince(0) - $total) < 1e-9
    && (int)$s->q('SELECT SUM(input_tokens) FROM usage')->fetchColumn() === 13 && $calls === 4, json_encode($s->q('SELECT * FROM usage')->fetchAll()));
check('prune: folding again changes nothing', !isset($s->prune($now, 90)['usage']) && (int)$s->q('SELECT COUNT(*) FROM usage')->fetchColumn() === 2);
$s->q('INSERT INTO tool_log(ts, tool, args, result) VALUES (?, ?, ?, ?)', [$now - 500 * $day, 'kb_list', '{}', 'very old']);
$s->prune($now, 0);
check('prune: 0 days keeps the whole tool log', (int)$s->q("SELECT COUNT(*) FROM tool_log WHERE result = 'very old'")->fetchColumn() === 1);
$idx = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(), 'name');
check('prune: the often-read columns are indexed', !array_diff(['usage_ts', 'jobs_status', 'jobs_directive', 'tasks_next_run'], $idx), json_encode($idx));
$plan = implode(' ', array_column($s->q('EXPLAIN QUERY PLAN SELECT COALESCE(SUM(cost_usd),0) FROM usage WHERE ts >= ?', [$now])->fetchAll(), 'detail'));
check('prune: the costs of the day read through the index', str_contains($plan, 'usage_ts'), $plan);

// archiving old jobs in one query, with the same rules as before
putenv("TALIXIO_DATA_DIR=$d/app"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none');
$app = App::fromEnv(); $st = $app->store;
$mk = function (string $status, int $finished, string $tags = '', string $kind = 'agent') use ($st) { $id = $st->addJob('j', 'x', 'fast', 1, $kind, null, 0, $tags); $st->updateJob($id, ['status' => $status, 'finished' => $finished]); return $id; };
$done = $mk('done', $now - 10 * $day); $tagged = $mk('done', $now - 10 * $day, 'keep'); $fresh = $mk('done', $now - $day); $failedOld = $mk('failed', $now - 100 * $day);
$running = $mk('running', 0); $worker = $mk('done', $now - 100 * $day, '', 'worker');
$n = $app->jobs()->autoArchive($now);
$arch = fn(int $id) => (int)$st->job($id)['archived'];
check('prune: done jobs are archived after a few days, tagged ones and recent ones not', $arch($done) === 1 && $arch($tagged) === 0 && $arch($fresh) === 0, json_encode([$arch($done), $arch($tagged), $arch($fresh)]));
check('prune: old finished ones are archived, running ones and workers never', $arch($failedOld) === 1 && $arch($running) === 0 && $arch($worker) === 0, (string)$n);
putenv('TALIXIO_DATA_DIR');
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
