<?php
declare(strict_types=1);
// Audit Q11, Q14, Q15: the worker keeps going past a routine it cannot schedule, runs its housekeeping even when a tick
// is slow, and a failing nightly backup is said once, spaced out, and shown as failed.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Worker};

$d = test_dir('worker');
$keep = [];
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_BACKUP_KEEP' => '3'] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv(); $app->cfg->notify = []; $app->cfg->heartbeatMinutes = 0;
$s = $app->store;
$now = strtotime('2026-11-12 10:37:41'); // not in the first 15 s of an hour
$s->addTask('Broken', 'say hi', 'not a cron at all', $now - 60);
$badId = (int)$s->db->lastInsertId();
$s->addTask('Fine', 'say hello', '0 9 * * *', $now - 60);
$fineId = (int)$s->db->lastInsertId();
$notes = fn() => (int)$s->q('SELECT COUNT(*) FROM notifications')->fetchColumn();
$n0 = $notes();
$w = new Worker($app);
$w->tick($now);
check('worker: a routine with a schedule it cannot read is paused, not fatal', (int)$s->task($badId)['enabled'] === 0);
check('worker: ... the user is told once', $notes() - $n0 >= 1 && str_contains((string)$s->q("SELECT body FROM notifications WHERE title = 'Broken'")->fetchColumn(), 'paused'));
check('worker: ... and the other routines still run', (int)$s->task($fineId)['last_run'] === $now && (int)$s->task($fineId)['next_run'] > $now);
check('worker: hourly and daily housekeeping run even off the hour', (int)$s->get('worker_last_hourly', '0') === $now && (int)$s->get('worker_last_daily', '0') === $now);
$w->tick($now + 120);
check('worker: ... and not again within the hour', (int)$s->get('worker_last_hourly', '0') === $now);

// a backup that cannot be written
file_put_contents("$d/not-a-dir", 'x'); $app->cfg->backupDir = "$d/not-a-dir/backups";
$night = strtotime('2026-11-13 03:30:00');
$s->set('backup_last', (string)($night - 21 * 3600)); $s->set('backup_error', ''); // yesterday's backup went fine
check('backup: due at night', $app->backup()->due($night));
$bnotes = fn() => (int)$s->q("SELECT COUNT(*) FROM notifications WHERE title = 'Backup failed'")->fetchColumn();
$w->tick($night);
$st = $app->backup()->status($night);
check('backup: a failed backup is recorded and shown as failed', $st['state'] === 'failed' && $st['error'] !== null, json_encode($st));
check('backup: the user is told', $bnotes() === 1);
check('backup: it is not tried again at once', !$app->backup()->due($night + 60) && !$app->backup()->due($night + 3 * 3600));
$w->tick($night + 6 * 3600 + 60);
check('backup: tried again a few hours later, and failing again says nothing new', $bnotes() === 1 && json_decode($s->get('backup_error'), true)['ts'] === $night + 6 * 3600 + 60);
$sec = array_column($app->privacy()->overview()['security'], null, 'label')['Nightly backups'];
check('backup: the Privacy page shows the failure', $sec['ok'] === false && $sec['state'] === 'failed', json_encode($sec));
$app->cfg->backupDir = "$d/backups";
$w->tick($night + 12 * 3600 + 120);
check('backup: once it works again, it is shown as fine', $app->backup()->status($night + 12 * 3600 + 200)['state'] === 'ok' && $s->get('backup_fail_notified') === '' && glob("$d/backups/talixio-*.tar.gz"));
foreach ($keep as $k => $v) $v === false ? putenv($k) : putenv("$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
