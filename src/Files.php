<?php
declare(strict_types=1);
namespace Talixio;

/** Uploaded and received files (images, PDFs, audio, text): stored on disk, text extracted for the model and for search. */
final class Files
{
    public function __construct(private App $app) {}

    public static function kind(string $mime, string $name): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' || str_ends_with(strtolower($name), '.pdf') => 'pdf',
            str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || (bool)preg_match('/\.(m4a|mp3|ogg|oga|opus|wav|webm|aac|flac|amr|3gp|mp4|mov)$/i', $name) => 'audio',
            str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml', 'text/csv'], true) => 'text',
            default => 'other',
        };
    }

    /** Store a file (moved from $tmp). Returns the new file id. */
    public function save(string $tmp, string $name, string $mime = ''): int
    {
        $c = $this->app->cfg;
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        }
        $name = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name)) ?: 'file', 0, 120);
        $dir = $c->filesDir() . '/' . date('Y/m');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $kind = self::kind($mime, $name);
        $st = $this->app->store;
        $st->q('INSERT INTO files(name, mime, kind, size, path, created) VALUES(?,?,?,?,?,?)', [$name, $mime, $kind, filesize($tmp), '', time()]);
        $id = (int)$st->db->lastInsertId();
        $path = "$dir/$id-$name";
        if (!@rename($tmp, $path) && !copy($tmp, $path)) throw new \RuntimeException('could not store the file');
        chmod($path, 0640);
        $duration = $kind === 'audio' ? self::duration($path) : null;
        $st->q('UPDATE files SET path=?, duration=? WHERE id=?', [$path, $duration, $id]);
        return $id;
    }

    public function get(int $id): ?array { return $this->app->store->q('SELECT * FROM files WHERE id=?', [$id])->fetch() ?: null; }

    public static function duration(string $path): ?float
    {
        $out = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        return is_numeric(trim((string)$out)) ? round((float)$out, 1) : null;
    }

    /** Readable text of a document or transcript (cached in the database). */
    public function text(int $id): string
    {
        $f = $this->get($id) ?? throw new \RuntimeException("no file $id");
        if ($f['text'] !== null && $f['text'] !== '') return $f['text'];
        $text = match ($f['kind']) {
            'pdf' => trim((string)shell_exec('pdftotext -layout -l 300 ' . escapeshellarg($f['path']) . ' - 2>/dev/null')),
            'text' => (string)file_get_contents($f['path'], false, null, 0, 2_000_000),
            'audio' => $this->app->transcriber()->transcribe($f, ($f['duration'] ?? 0) > $this->app->cfg->voiceNoteMaxSeconds),
            default => '',
        };
        if ($f['kind'] === 'pdf' && $text === '') $text = '(No text found in this PDF: it may be a scan. Text extraction needs pdftotext; scanned pages are not read yet.)';
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1');
        $this->app->store->q('UPDATE files SET text=? WHERE id=?', [$text, $id]);
        if ($text !== '' && in_array($f['kind'], ['pdf', 'text', 'audio'], true)) {
            $this->app->store->addDoc($f['kind'] === 'audio' ? 'transcript' : 'file', $id, ($f['kind'] === 'audio' ? 'transcript: ' : 'file: ') . $f['name'], mb_substr($text, 0, 200000));
        }
        return $text;
    }

    /** Image as base64, downscaled if large (providers limit image size). */
    public function imagePart(int $id): array
    {
        $f = $this->get($id) ?? throw new \RuntimeException("no file $id");
        $data = (string)file_get_contents($f['path']);
        $mime = in_array($f['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? $f['mime'] : 'image/jpeg';
        $info = @getimagesizefromstring($data);
        if (function_exists('imagecreatefromstring') && ($info === false || max($info[0], $info[1]) > 1600 || strlen($data) > 3_500_000 || $mime !== $f['mime'])) {
            $img = @imagecreatefromstring($data);
            if ($img) {
                $w = imagesx($img); $h = imagesy($img); $scale = min(1, 1600 / max($w, $h));
                $dst = imagescale($img, max(1, (int)($w * $scale)), max(1, (int)($h * $scale)));
                ob_start(); imagejpeg($dst, null, 85); $data = (string)ob_get_clean();
                $mime = 'image/jpeg';
            }
        }
        return ['type' => 'image', 'mime' => $mime, 'data' => base64_encode($data)];
    }

    /** Content parts for a message with attachments. */
    public function parts(string $text, array $ids): array
    {
        $parts = $text !== '' ? [['type' => 'text', 'text' => $text]] : [];
        foreach ($ids as $id) {
            $f = $this->get((int)$id);
            if (!$f) continue;
            if ($f['kind'] === 'image') { $parts[] = ['type' => 'text', 'text' => "[Attached image #{$f['id']}: {$f['name']}]"]; $parts[] = $this->imagePart((int)$id); continue; }
            $body = in_array($f['kind'], ['pdf', 'text', 'audio'], true) ? $this->text((int)$id) : '(binary file, cannot be read)';
            $label = $f['kind'] === 'audio' ? 'Transcript of attached audio' : 'Attached document';
            $parts[] = ['type' => 'text', 'text' => "[$label #{$f['id']}: {$f['name']}]\n" . mb_substr($body, 0, 40000)
                . (mb_strlen($body) > 40000 ? "\n[... truncated; use file_read with id {$f['id']} and offset to read more]" : '')];
        }
        return $parts;
    }

    /** One-line reference kept in the conversation history. */
    public function reference(array $ids): string
    {
        $refs = [];
        foreach ($ids as $id) {
            $f = $this->get((int)$id);
            if ($f) $refs[] = "[attached {$f['kind']} #{$f['id']}: {$f['name']}" . ($f['duration'] ? ', ' . gmdate($f['duration'] >= 3600 ? 'G:i:s' : 'i:s', (int)$f['duration']) : '') . ']';
        }
        return implode("\n", $refs);
    }
}
