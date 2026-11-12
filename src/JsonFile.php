<?php
declare(strict_types=1);
namespace Talixio;

/**
 * One safe way to keep state in a JSON file (audit Q10, Q12): written to a temporary file next to it, checked (every byte
 * written), then renamed over the old one, so a full disk or a crash leaves the previous content whole; read-modify-write
 * under an exclusive lock, so two processes updating it at once never lose each other's change.
 */
final class JsonFile
{
    /** Tests only: called with the path before each write; may throw to simulate a failed write. */
    public static $failHook = null;

    /** The file's data, [] when it is missing or not JSON; a file kept sealed (Secrets) is opened unless !$open. */
    public static function read(string $path, bool $open = true): array
    {
        $raw = @file_get_contents($path);
        $d = $raw === false ? null : json_decode($raw, true);
        return is_array($d) ? ($open ? Secrets::openArray($d) : $d) : [];
    }

    /** Replace the file's content with $data, atomically. Throws (the old file untouched) when it cannot be written. */
    public static function write(string $path, array $data, int $mode = 0600, int $flags = 0, bool $seal = false): void
    {
        if ($seal) $data = Secrets::sealArray($data); // the whole content encrypted, when this server has a key (audit S22)
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | $flags); // $flags: JSON_PRETTY_PRINT for a file people read
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        try {
            if (self::$failHook) (self::$failHook)($path);
            $n = @file_put_contents($tmp, $json);
            if ($n !== strlen($json)) throw new \RuntimeException('could not write ' . basename($path) . ' (disk full?)');
            @chmod($tmp, $mode);
            if (!@rename($tmp, $path)) throw new \RuntimeException('could not replace ' . basename($path));
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    /**
     * Read, change and write the file under an exclusive lock held across the three steps. $fn gets the current data and
     * returns [new data, result] (new data null: nothing is written). Returns the result. Throws if the lock cannot be had.
     */
    public static function update(string $path, callable $fn, int $mode = 0600, int $flags = 0, bool $seal = false): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $fh = @fopen("$path.lock", 'c');
        if (!$fh || !flock($fh, LOCK_EX)) throw new \RuntimeException('could not lock ' . basename($path));
        try {
            [$data, $ret] = $fn(self::read($path));
            if ($data !== null) self::write($path, $data, $mode, $flags, $seal);
            return $ret;
        } finally {
            flock($fh, LOCK_UN); fclose($fh);
        }
    }
}
