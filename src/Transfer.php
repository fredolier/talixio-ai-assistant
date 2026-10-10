<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Moving an assistant to another server, whole: conversations, memory, skills, library, tasks, settings and
 * keys, password. The old server makes a transfer code (Settings → Move to another server); the new one
 * pulls a copy with it (admin page, or `talixio transfer-pull`), as many times as needed. A test copy
 * stays paused (no channels, no routines) so the two never answer the same messages; the final copy
 * pauses the old one instead and points it to the new address.
 */
final class Transfer
{
    public const TTL = 86400;

    public function __construct(private App $app) {}

    /** A new code (shown once), valid a day; replaces the previous one. */
    public function create(): array
    {
        $code = 'tx-' . bin2hex(random_bytes(20));
        $this->app->store->set('transfer_code', json_encode(['hash' => hash('sha256', $code), 'expires' => time() + self::TTL]));
        return ['code' => $code, 'expires' => time() + self::TTL, 'url' => $this->app->shares()->baseUrl()];
    }

    public function cancel(): void { $this->app->store->set('transfer_code', ''); }

    public function status(): array
    {
        $c = json_decode($this->app->store->get('transfer_code', ''), true);
        $active = is_array($c) && $c['expires'] > time();
        return ['code_active' => $active, 'code_expires' => $active ? $c['expires'] : null, 'paused' => self::paused($this->app->store),
            'last_export' => json_decode($this->app->store->get('transfer_last', ''), true) ?: null];
    }

    /** Paused by a transfer: ['reason' => 'copy'|'moved', 'url' => the other server, 'ts' => when], or null. */
    public static function paused(Store $store): ?array
    {
        $p = json_decode($store->get('paused', ''), true);
        return is_array($p) && !empty($p['reason']) ? $p : null;
    }

    /** "Use this one": a test copy becomes the real assistant (or the old one is woken up again). */
    public function resume(): void { $this->app->store->set('paused', ''); }

    private function valid(string $code): bool
    {
        $c = json_decode($this->app->store->get('transfer_code', ''), true);
        return is_array($c) && $c['expires'] > time() && $code !== '' && hash_equals((string)$c['hash'], hash('sha256', $code));
    }

    /** GET /api/transfer/export (no session; the code in "Authorization: Bearer"): the whole data folder as tar.gz. */
    public function export(string $code, bool $final, string $to): void
    {
        $auth = new Auth($this->app);
        $ip = Auth::clientIp();
        if ($auth->lockedFor($ip) > 0) { http_response_code(429); echo 'too many attempts'; return; }
        if (!$this->valid($code)) { $auth->failed($ip); sleep(1); http_response_code(403); echo 'invalid or expired transfer code'; return; }
        $c = $this->app->cfg;
        $tmp = sys_get_temp_dir() . '/talixio-transfer-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        try {
            $this->app->store->db->exec('VACUUM INTO ' . $this->app->store->db->quote("$tmp/talixio.db")); // consistent copy while running
            $ex = ['./talixio.db', './talixio.db-wal', './talixio.db-shm', './backups', './backup-snapshot.db', './.incoming', './.previous-*',
                './previews', './sessions', './*.lock', './update-status.json', './mcp-cache.json', './share-inbox']; // rebuilt there, or this server's own
            $cmd = 'tar -czf - -C ' . escapeshellarg($tmp) . ' talixio.db -C ' . escapeshellarg($c->dataDir) . ' '
                . implode(' ', array_map(fn($e) => '--exclude=' . escapeshellarg($e), $ex)) . ' . 2>/dev/null';
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="talixio-transfer.tar.gz"');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');
            $p = popen($cmd, 'r');
            $bytes = 0;
            while (!feof($p) && ($chunk = fread($p, 1 << 20)) !== false && $chunk !== '') { echo $chunk; flush(); $bytes += strlen($chunk); }
            $ok = pclose($p) === 0 && !connection_aborted();
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
        $to = mb_substr(preg_replace('/[^\w.:\/-]/', '', $to), 0, 200);
        $this->app->store->set('transfer_last', json_encode(['ts' => time(), 'ip' => $ip, 'bytes' => $bytes, 'final' => $final, 'ok' => $ok, 'to' => $to]));
        Log::info("transfer: copy of the data sent to $ip ($bytes bytes" . ($final ? ', final' : '') . ($ok ? '' : ', interrupted') . ')');
        if (!$ok) return;
        if ($final) { // the new server takes over: this one stops answering and points there
            $this->app->store->set('paused', json_encode(['reason' => 'moved', 'url' => $to, 'ts' => time()]));
            $this->cancel();
        }
        try {
            $this->app->notifier()->notify(I18n::t($this->app, 'Transfer'), $final
                ? I18n::t($this->app, 'Your assistant was moved to another server ({to}). This one is paused: it no longer answers or runs routines.', ['to' => $to])
                : I18n::t($this->app, 'A copy of your assistant was taken for another server, from {ip}.', ['ip' => $ip]));
        } catch (\Throwable $e) { Log::warn("notify: {$e->getMessage()}"); }
    }

    /**
     * On the new server, with its services stopped: download the copy into $dataDir/.incoming, check it, then
     * swap (the data that was there is kept in $dataDir/.previous-<date>, the last one only).
     * $publicUrl: this assistant's new address (links, notifications). Returns a summary line.
     */
    public static function pull(string $dataDir, string $source, string $code, bool $final, string $publicUrl = '', ?callable $say = null): string
    {
        $say ??= fn(string $m) => fwrite(STDERR, "$m\n");
        $source = rtrim(trim($source), '/');
        if (!preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $source)) throw new \InvalidArgumentException('The source is the old assistant\'s address, e.g. https://assistant.example.com/fred');
        if (!preg_match('/^tx-[0-9a-f]{40}$/', trim($code))) throw new \InvalidArgumentException('The transfer code starts with tx- (Settings → Move to another server, on the old server).');
        $in = "$dataDir/.incoming";
        exec('rm -rf ' . escapeshellarg($in));
        mkdir($in, 0700, true);
        $say("Downloading from {$source}…");
        $tar = popen('tar -xzf - -C ' . escapeshellarg($in) . ' >/dev/null 2>&1', 'w');
        $status = 0; $bytes = 0; $last = 0;
        $ch = curl_init("$source/api/transfer/export?" . http_build_query(['final' => $final ? 1 : 0, 'to' => $publicUrl]));
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim($code)], CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 300, CURLOPT_FAILONERROR => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $h) use (&$status) { if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1]; return strlen($h); },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$status, &$bytes, &$last, $tar, $say) {
                if ($status !== 200) return 0; // an error page is not an archive
                $bytes += strlen($chunk);
                if ($bytes - $last > 50 << 20) { $last = $bytes; $say(sprintf('%d MB received…', $bytes >> 20)); }
                return fwrite($tar, $chunk);
            }]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $tarOk = pclose($tar) === 0;
        if ($status === 403) throw new \RuntimeException('The old server refused the code: it is wrong, expired (a day), or was replaced by a newer one.');
        if ($status === 429) throw new \RuntimeException('The old server blocks this address for a while after wrong codes.');
        if ($status !== 200) throw new \RuntimeException("The old server did not answer with a copy (HTTP $status" . ($err ? ", $err" : '') . '). Is the address right, and is it Talixio 0.15 or later?');
        if ($err || !$tarOk) throw new \RuntimeException('The download was interrupted' . ($err ? ": $err" : '') . '. Nothing was changed here; try again.');
        $db = "$in/talixio.db";
        try {
            $pdo = new \PDO("sqlite:$db");
            if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new \RuntimeException('damaged');
            $msgs = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
            $files = (int)$pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
            // the copy's own state: no code to copy it again from here, paused unless it is the final move
            $kv = $pdo->prepare('INSERT INTO kv(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
            $kv->execute(['transfer_code', '']);
            $kv->execute(['paused', $final ? '' : json_encode(['reason' => 'copy', 'url' => $source, 'ts' => time()])]);
            $pdo = null;
        } catch (\Throwable $e) {
            throw new \RuntimeException('The copy received is not a Talixio database (' . $e->getMessage() . '). Nothing was changed here.');
        }
        if ($publicUrl !== '') Settings::save($in, ['TALIXIO_PUBLIC_URL' => rtrim($publicUrl, '/')]);
        // swap: what was here goes to .previous-<date> (only the last one is kept)
        foreach (glob("$dataDir/.previous-*", GLOB_ONLYDIR) ?: [] as $old) exec('rm -rf ' . escapeshellarg($old));
        $prev = "$dataDir/.previous-" . date('Ymd-His');
        mkdir($prev, 0700);
        foreach (array_diff(scandir($dataDir) ?: [], ['.', '..', '.incoming', basename($prev)]) as $e) rename("$dataDir/$e", "$prev/$e");
        foreach (array_diff(scandir($in) ?: [], ['.', '..']) as $e) rename("$in/$e", "$dataDir/$e");
        rmdir($in);
        return sprintf('Copied: %d messages, %d documents, %d MB.%s', $msgs, $files, $bytes >> 20,
            $final ? ' This is now the assistant; the old server is paused.' : ' This copy is paused (no channels, no routines) until the final transfer, or until you choose "Use this one" in it.');
    }
}
