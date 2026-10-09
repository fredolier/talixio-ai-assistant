<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Files (uploads, attachments, recordings, notes): stored on disk, organised in folders (see Library),
 * text extracted for the model and for search: PDF (OCR for scans), Office/OpenDocument, HTML, email,
 * text, audio (transcribed) and images (described). Extraction runs in the background for library uploads.
 */
final class Files
{
    public function __construct(private App $app) {}

    public static function kind(string $mime, string $name): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' || str_ends_with(strtolower($name), '.pdf') => 'pdf',
            str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || (bool)preg_match('/\.(m4a|mp3|ogg|oga|opus|wav|webm|aac|flac|amr|3gp|mp4|mov)$/i', $name) => 'audio',
            (bool)preg_match('/\.(docx|xlsx|pptx|odt|ods|odp)$/i', $name) || str_contains($mime, 'officedocument') || str_contains($mime, 'opendocument') => 'office',
            str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml', 'message/rfc822'], true)
                || (bool)preg_match('/\.(md|txt|csv|json|html?|eml|xml|log)$/i', $name) => 'text',
            default => 'other',
        };
    }

    /** Store a file (moved from $tmp) in a library folder. Returns the new file id. */
    public function save(string $tmp, string $name, string $mime = '', string $folder = '/Inbox'): int
    {
        $folder = Library::normalize($folder);
        $c = $this->app->cfg;
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        }
        $name = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name)) ?: 'file', 0, 120);
        $dir = $c->filesDir() . '/' . date('Y/m');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $kind = self::kind($mime, $name);
        $st = $this->app->store;
        $this->app->library()->ensureFolder($folder);
        $st->q('INSERT INTO files(name, mime, kind, size, path, created, folder, status) VALUES(?,?,?,?,?,?,?,?)',
            [$name, $mime, $kind, filesize($tmp), '', time(), $folder, $kind === 'other' ? 'ready' : 'pending']);
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

    /** Readable text of a document, transcript or image description (cached; indexed for search once). */
    public function text(int $id): string
    {
        $f = $this->get($id) ?? throw new \RuntimeException("no file $id");
        if ($f['text'] !== null && $f['status'] === 'ready') return $f['text'];
        // claim it, so the worker and a chat request never read (and pay for) the same file twice
        $claimed = $this->app->store->q("UPDATE files SET status = 'processing' WHERE id = ? AND status != 'processing'", [$id])->rowCount() === 1;
        if (!$claimed) {
            $until = time() + 900;
            while (time() < $until) {
                usleep(500_000);
                $f = $this->get($id);
                if (!$f || $f['status'] !== 'processing') break;
            }
            if ($f && $f['status'] === 'ready') return (string)$f['text'];
            if ($f && $f['status'] === 'failed') throw new \RuntimeException((string)$f['text']);
            $this->app->store->q("UPDATE files SET status = 'processing' WHERE id = ?", [$id]); // stale claim (crashed reader): take over
        }
        try {
            $text = $this->extract($f);
            if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1');
            $this->app->store->q("UPDATE files SET text=?, status='ready' WHERE id=?", [$text, $id]);
            $this->index($id);
            return $text;
        } catch (\Throwable $e) {
            $this->app->store->q("UPDATE files SET status='failed', text=? WHERE id=?", ['(could not read this file: ' . $e->getMessage() . ')', $id]);
            throw $e;
        }
    }

    private function extract(array $f): string
    {
        $path = $f['path']; $name = strtolower($f['name']);
        switch ($f['kind']) {
            case 'pdf':
                $text = trim((string)shell_exec('pdftotext -layout -l 300 ' . escapeshellarg($path) . ' - 2>/dev/null'));
                if (mb_strlen(preg_replace('/\s+/', '', $text)) < 20) $text = $this->ocrPdf($f) ?: '(No text found in this PDF: it seems to be a scan and image reading is off or unavailable.)';
                return $text;
            case 'office': return self::officeText($path, $name);
            case 'audio': return $this->app->transcriber()->transcribe($f, ($f['duration'] ?? 0) > $this->app->cfg->voiceNoteMaxSeconds);
            case 'image': return $this->describeImage($f);
            case 'text':
                $raw = (string)file_get_contents($path, false, null, 0, 5_000_000);
                if (preg_match('/\.html?$/', $name) || str_contains($f['mime'], 'html')) return Http::htmlToText($raw);
                if (str_ends_with($name, '.eml') || $f['mime'] === 'message/rfc822') {
                    $m = Mime::parse($raw); $h = $m['headers'];
                    return 'From: ' . Mime::decodeHeader($h['from'] ?? '') . "\nTo: " . Mime::decodeHeader($h['to'] ?? '') . "\nDate: " . ($h['date'] ?? '')
                        . "\nSubject: " . Mime::decodeHeader($h['subject'] ?? '') . "\n\n" . $m['text'];
                }
                return $raw;
            default: return '';
        }
    }

    /** Text of .docx/.xlsx/.pptx and OpenDocument files (they are zip archives of XML). */
    public static function officeText(string $path, string $name): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Office files need php-zip: run sudo ./install.sh again (or apt install php-zip)');
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('not a valid Office/OpenDocument file');
        $xmlText = fn(string $xml) => trim(html_entity_decode(strip_tags(preg_replace(['#</w:p>|</a:p>|</text:p>|</text:h>|<text:line-break/>#', '#<w:tab/>|</table:table-cell>#'], ["\n", "\t"], $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $out = [];
        if (str_ends_with($name, '.docx')) {
            $out[] = $xmlText((string)$zip->getFromName('word/document.xml'));
        } elseif (str_ends_with($name, '.pptx')) {
            for ($i = 1; ($x = $zip->getFromName("ppt/slides/slide$i.xml")) !== false; $i++) $out[] = "--- Slide $i\n" . $xmlText($x);
        } elseif (str_ends_with($name, '.xlsx')) {
            $shared = [];
            if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                preg_match_all('#<si>(.*?)</si>#s', $ss, $m);
                foreach ($m[1] as $si) $shared[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            for ($i = 1; ($x = $zip->getFromName("xl/worksheets/sheet$i.xml")) !== false && $i <= 30; $i++) {
                $rows = [];
                preg_match_all('#<row[^>]*>(.*?)</row>#s', $x, $rm);
                foreach (array_slice($rm[1], 0, 5000) as $row) {
                    preg_match_all('#<c ([^>]*)>(?:<f>.*?</f>)?(?:<v>(.*?)</v>|<is>(.*?)</is>)?</c>#s', $row, $cm, PREG_SET_ORDER);
                    $cells = array_map(fn($c) => str_contains($c[1], 't="s"') ? ($shared[(int)($c[2] ?? 0)] ?? '') : html_entity_decode(strip_tags($c[3] ?? $c[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'), $cm);
                    if (array_filter($cells, 'strlen')) $rows[] = implode("\t", $cells);
                }
                $out[] = "--- Sheet $i\n" . implode("\n", $rows);
            }
        } else { // OpenDocument
            $out[] = $xmlText((string)$zip->getFromName('content.xml'));
        }
        $zip->close();
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n\n", $out)));
    }

    /** One short vision call (fast tier) when allowed and affordable; null otherwise. */
    private function vision(array $parts, string $instruction): ?string
    {
        $c = $this->app->cfg;
        if (!$c->visionIndex || $c->provider === 'echo' || $this->app->budget()->status()['state'] !== 'ok') return null;
        $tier = $c->tier('fast');
        try {
            $text = $this->app->provider($tier)->complete($instruction, '', [['role' => 'user', 'content' => $parts]], $this->app->tools->only('none'),
                function (array $u) use ($tier) { $this->app->store->addUsage($u, $this->app->pricing()->cost($u), 'indexing', $tier->name, null); });
        } catch (Abort $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warn("image reading: {$e->getMessage()}");
            return null;
        }
        return trim($text) ?: null;
    }

    private function describeImage(array $f): string
    {
        $d = $this->vision([['type' => 'text', 'text' => 'Describe this image for a search index.'], $this->imagePart((int)$f['id'])],
            'You index images for a personal knowledge base. Transcribe all readable text exactly (documents, receipts, whiteboards, screenshots), '
            . 'then describe what the image shows in 2-4 factual sentences (objects, people, places, dates, amounts). No commentary.');
        return $d !== null ? "Image description:\n$d" : '';
    }

    /** Scanned PDF: render the first pages and read them with the model. */
    private function ocrPdf(array $f): string
    {
        if (!$this->app->cfg->visionIndex || trim((string)shell_exec('command -v pdftoppm 2>/dev/null')) === '') return '';
        $tmp = sys_get_temp_dir() . '/talixio-ocr-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            exec('pdftoppm -r 110 -l 15 -jpeg ' . escapeshellarg($f['path']) . ' ' . escapeshellarg("$tmp/p"));
            $pages = glob("$tmp/p*.jpg") ?: [];
            sort($pages, SORT_NATURAL);
            $out = [];
            foreach ($pages as $i => $pg) {
                $t = $this->vision([['type' => 'text', 'text' => 'Transcribe this page.'], ['type' => 'image', 'mime' => 'image/jpeg', 'data' => base64_encode((string)file_get_contents($pg))]],
                    'You transcribe scanned document pages exactly, keeping the reading order and line breaks. Output only the text of the page.');
                if ($t === null) break;
                $out[] = '--- Page ' . ($i + 1) . "\n$t";
            }
            return implode("\n\n", $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    /** (Re)build the search index entries for a file, labelled with its library path. */
    public function index(int $id): void
    {
        $f = $this->get($id);
        if (!$f) return;
        $this->app->store->deleteDocs(['file', 'transcript'], $id);
        $text = (string)$f['text'];
        if (trim($text) === '' || str_starts_with($text, '(could not read') || str_starts_with($text, '(No text found')) return;
        $this->app->store->addDoc($f['kind'] === 'audio' ? 'transcript' : 'file', $id, Library::label($f), mb_substr($text, 0, 800000), (int)$f['created']);
    }

    /** @var resource|null background `talixio index` process started by the worker */
    private static $indexer = null;

    /** Worker: start one background indexer when files wait (transcription and OCR can take minutes). */
    public function startIndexer(): void
    {
        if (self::$indexer !== null) {
            if (proc_get_status(self::$indexer)['running']) return;
            proc_close(self::$indexer);
            self::$indexer = null;
        }
        if (!$this->app->store->q("SELECT 1 FROM files WHERE status = 'pending' LIMIT 1")->fetchColumn()) return;
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'index'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', 'php://stderr', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
        if (is_resource($p)) self::$indexer = $p;
    }

    /** Files left half-read by a restart go back to the queue. */
    public function recoverInterrupted(): void { $this->app->store->q("UPDATE files SET status = 'pending' WHERE status = 'processing'"); }

    /** Read and index waiting files (runs in the `talixio index` process; time-boxed). */
    public function processPending(float $seconds = 20): int
    {
        $n = 0; $until = microtime(true) + $seconds;
        foreach ($this->app->store->q("SELECT id FROM files WHERE status='pending' ORDER BY id LIMIT 20")->fetchAll() as $r) {
            if (microtime(true) > $until) break;
            try { $this->text((int)$r['id']); } catch (\Throwable $e) { Log::warn("indexing file {$r['id']}: {$e->getMessage()}"); }
            $n++;
        }
        return $n;
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
            $body = in_array($f['kind'], ['pdf', 'text', 'audio', 'office'], true) ? $this->text((int)$id) : '(binary file, cannot be read)';
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
