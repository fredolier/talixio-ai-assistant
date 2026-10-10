<?php
declare(strict_types=1);
// A long action of the agent, run on its own: update | rollback | pull-model | transfer. Progress in <state>/job.json.
require __DIR__ . '/../../src/autoload.php';

$state = getenv('TALIXIO_STATE_DIR') ?: '/state';
[, $kind, $arg] = $argv + [1 => '', 2 => ''];
$fleet = new Talixio\Fleet($state);
$log = [];
$progress = function (string $phase, string $message) use ($state, $kind, &$log): void {
    $log[] = date('H:i:s') . ' ' . $message;
    $job = ['kind' => $kind, 'state' => in_array($phase, ['done', 'failed'], true) ? $phase : 'running', 'phase' => $phase, 'message' => $message,
        'log' => array_slice($log, -40), 'updated' => time()];
    file_put_contents("$state/job.json.tmp", json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename("$state/job.json.tmp", "$state/job.json");
};
try {
    match ($kind) {
        'update' => $fleet->update($arg !== '' ? $arg : 'main', $progress),
        'rollback' => $fleet->rollback($progress),
        'pull-model' => $progress('done', $fleet->pullModel($arg !== '' ? $arg : null)),
        'transfer' => (function () use ($fleet, $arg, $progress) { // arg: JSON {id, name, source, code, final}
            $a = json_decode($arg, true) ?: [];
            $fleet->transferIn((string)($a['id'] ?? ''), (string)($a['name'] ?? ''), (string)($a['source'] ?? ''), (string)($a['code'] ?? ''), !empty($a['final']), $progress);
        })(),
    };
} catch (Throwable $e) {
    $progress('failed', $e->getMessage());
    exit(1);
}
