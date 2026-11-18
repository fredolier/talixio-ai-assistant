<?php
declare(strict_types=1);
namespace Talixio;

/**
 * A recording saved while it is made (1.41.0): the browser sends a piece every few seconds, appended in order, so a closed
 * tab, a dead battery or a lost network loses at most the last seconds. Finished, it becomes a file like any upload;
 * left unfinished, it is offered back when the app opens again. One folder: <data>/recordings/<id>.part + <id>.json.
 */
final class Recordings
{
    public const MAX_BYTES = 2_000_000_000; // about 100 hours at the recorder's 32 kbit/s: a runaway, not a meeting
    public const KEEP_DAYS = 7;             // an interrupted recording nobody came back for

    public function __construct(private App $app) {}

    private function dir(): string
    {
        $d = $this->app->cfg->dataDir . '/recordings';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    private function meta(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
        $m = json_decode((string)@file_get_contents($this->dir() . "/$id.json"), true);
        return is_array($m) ? $m : null;
    }

    private function put(string $id, array $m): void
    {
        $f = $this->dir() . "/$id.json";
        file_put_contents("$f.tmp", json_encode($m)); rename("$f.tmp", $f);
    }

    public function start(string $name, string $mime, string $folder, bool $meeting): array
    {
        $this->sweep();
        $id = bin2hex(random_bytes(8));
        $name = Library::cleanName($name !== '' ? $name : 'recording.webm');
        $mime = preg_match('#^audio/[\w.+-]+(;.*)?$#', $mime) ? $mime : 'audio/webm';
        touch($this->dir() . "/$id.part");
        $m = ['id' => $id, 'name' => $name, 'mime' => $mime, 'folder' => $folder, 'meeting' => $meeting, 'next' => 0, 'bytes' => 0, 'created' => time(), 'updated' => time()];
        $this->put($id, $m);
        return $m;
    }

    /**
     * Appends piece $seq when it is the next one. A piece sent again (its answer was lost) is acknowledged, not appended twice;
     * one sent ahead of a missing piece is refused with the one expected, so the browser sends that first.
     * @return array{0:int,1:array} HTTP status and answer
     */
    public function chunk(string $id, int $seq, string $data): array
    {
        $m = $this->meta($id) ?? throw new \DomainException('This recording is not on the server any more.');
        if ($seq < $m['next']) return [200, ['next' => $m['next'], 'bytes' => $m['bytes']]];
        if ($seq > $m['next']) return [409, ['error' => 'a piece is missing', 'next' => $m['next']]];
        if ($m['bytes'] + strlen($data) > self::MAX_BYTES) throw new \LengthException('This recording is too long.');
        $fh = fopen($this->dir() . "/$id.part", 'ab');
        if (!$fh || !flock($fh, LOCK_EX)) throw new \RuntimeException('could not save the recording');
        try {
            $m = $this->meta($id) ?? $m; // another request may have appended it while this one waited
            if ($seq !== $m['next']) return [$seq < $m['next'] ? 200 : 409, ['next' => $m['next'], 'bytes' => $m['bytes']]];
            fwrite($fh, $data); fflush($fh);
            $m['next']++; $m['bytes'] += strlen($data); $m['updated'] = time();
            $this->put($id, $m);
        } finally { flock($fh, LOCK_UN); fclose($fh); }
        return [200, ['next' => $m['next'], 'bytes' => $m['bytes']]];
    }

    /** The recording becomes a file in the library (its duration read, like any upload); returns [file id, meeting]. */
    public function finish(string $id, string $name = ''): array
    {
        $m = $this->meta($id) ?? throw new \DomainException('This recording is not on the server any more.');
        $part = $this->dir() . "/$id.part";
        if (!$m['bytes'] || !is_file($part)) { $this->discard($id); throw new \DomainException('Nothing was recorded.'); }
        $fid = $this->app->files()->save($part, $name !== '' ? Library::cleanName($name) : $m['name'], $m['mime'], $m['folder'] !== '' ? $m['folder'] : Library::INBOX);
        @unlink($part); @unlink($this->dir() . "/$id.json");
        return [$fid, (bool)$m['meeting']];
    }

    public function discard(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) return;
        @unlink($this->dir() . "/$id.part"); @unlink($this->dir() . "/$id.json");
    }

    /** Unfinished recordings, newest first, with how long they are when ffprobe can tell. */
    public function pending(): array
    {
        $this->sweep();
        $out = [];
        foreach (glob($this->dir() . '/*.json') ?: [] as $f) {
            $m = json_decode((string)file_get_contents($f), true);
            if (!is_array($m) || !$m['bytes']) continue;
            $out[] = $m + ['duration' => Files::duration($this->dir() . "/{$m['id']}.part")];
        }
        usort($out, fn($a, $b) => $b['updated'] <=> $a['updated']);
        return $out;
    }

    /** Recordings nobody came back for in a week, and empty ones left by a start that never sent anything. */
    private function sweep(): void
    {
        foreach (glob($this->dir() . '/*.json') ?: [] as $f) {
            $m = json_decode((string)@file_get_contents($f), true);
            $age = time() - (int)($m['updated'] ?? 0);
            if (!is_array($m) || $age > self::KEEP_DAYS * 86400 || (!$m['bytes'] && $age > 86400)) $this->discard((string)($m['id'] ?? basename($f, '.json')));
        }
    }
}
