<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Daily backup of everything that matters: database (consistent snapshot), memory files and skills,
 * received files, push keys, Signal registration. Kept in data/backups (TALIXIO_BACKUP_DIR to put them
 * elsewhere, e.g. a mounted disk), last TALIXIO_BACKUP_KEEP archives.
 */
final class Backup
{
    public function __construct(private App $app) {}

    public function dir(): string { return $this->app->cfg->backupDir ?: $this->app->cfg->dataDir . '/backups'; }

    public function run(): string
    {
        $c = $this->app->cfg;
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException("the backup folder $dir cannot be created");
        $snap = $c->dataDir . '/backup-snapshot.db';
        @unlink($snap);
        $this->app->store->db->exec('VACUUM INTO ' . $this->app->store->db->quote($snap)); // consistent copy while running
        $items = array_filter(['backup-snapshot.db', 'workspace', 'vapid.pem', 'signal', $c->backupFiles ? 'files' : null], fn($i) => $i && file_exists("{$c->dataDir}/$i"));
        $out = $dir . '/talixio-' . date('Ymd-His') . '.tar.gz';
        exec('tar -czf ' . escapeshellarg($out) . ' -C ' . escapeshellarg($c->dataDir) . ' --exclude=signal/attachments ' . implode(' ', array_map('escapeshellarg', $items)) . ' 2>&1', $o, $code);
        @unlink($snap);
        if ($code !== 0) { @unlink($out); throw new \RuntimeException('backup failed: ' . implode(' ', $o)); }
        chmod($out, 0600);
        $all = glob($dir . '/talixio-*.tar.gz') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - max(1, $c->backupKeep))) as $old) @unlink($old);
        $this->app->store->set('backup_last', (string)time());
        $this->app->store->set('backup_last_file', $out);
        return $out;
    }

    /**
     * The worker's nightly run (audit Q15): a failure is recorded, said once (not at every try), and tried again
     * only after RETRY_AFTER; a success clears it.
     */
    public function nightly(int $now): string
    {
        $st = $this->app->store;
        try { $out = $this->run(); }
        catch (\Throwable $e) {
            $st->set('backup_error', json_encode(['ts' => $now, 'error' => mb_substr($e->getMessage(), 0, 500)]));
            if ($st->get('backup_fail_notified', '') === '') {
                $st->set('backup_fail_notified', (string)$now);
                try { $this->app->notifier()->notify('Backup failed', I18n::t($this->app, 'The nightly backup failed: {e}. It is tried again in a few hours; the Privacy page shows its state.', ['e' => mb_substr($e->getMessage(), 0, 300)])); }
                catch (\Throwable $n) { Log::warn("backup notify: {$n->getMessage()}"); }
            }
            throw $e;
        }
        $st->set('backup_error', ''); $st->set('backup_fail_notified', '');
        return $out;
    }

    public const RETRY_AFTER = 6 * 3600;

    /** off | never (not run yet) | ok (a backup in the last two days) | late | failed, with the last success and error. */
    public function status(int $now = 0): array
    {
        $now = $now ?: time(); $st = $this->app->store;
        $last = (int)$st->get('backup_last', '0'); $err = json_decode($st->get('backup_error', ''), true) ?: null;
        $state = $this->app->cfg->backupKeep <= 0 ? 'off' : ($err && (int)$err['ts'] >= $last ? 'failed' : (!$last ? 'never' : ($now - $last > 48 * 3600 ? 'late' : 'ok')));
        return ['state' => $state, 'last' => $last ?: null, 'error' => $err['error'] ?? null];
    }

    /** Worker tick: once a day, at night; after a failure, not before RETRY_AFTER. */
    public function due(int $now): bool
    {
        if ($this->app->cfg->backupKeep <= 0) return false;
        $err = json_decode($this->app->store->get('backup_error', ''), true);
        if (is_array($err) && $now - (int)($err['ts'] ?? 0) < self::RETRY_AFTER) return false;
        $last = (int)$this->app->store->get('backup_last', '0');
        return $now - $last > 20 * 3600 && ((int)date('G', $now) >= 3 || $now - $last > 30 * 3600);
    }
}
