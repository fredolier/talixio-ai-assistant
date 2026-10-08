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
        if (!is_dir($dir)) mkdir($dir, 0700, true);
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

    /** Worker tick: once a day, at night. */
    public function due(int $now): bool
    {
        if ($this->app->cfg->backupKeep <= 0) return false;
        $last = (int)$this->app->store->get('backup_last', '0');
        return $now - $last > 20 * 3600 && ((int)date('G', $now) >= 3 || $now - $last > 30 * 3600);
    }
}
