<?php
declare(strict_types=1);
namespace Talixio;

/** Speech to text: Gemini (long recordings, speaker labels) or OpenAI. Audio is compressed with ffmpeg first. */
final class Transcriber
{
    public function __construct(private App $app) {}

    public function enabled(): bool { return $this->app->cfg->transcribe !== 'off'; }

    public function transcribe(array $file, bool $meeting): string
    {
        $c = $this->app->cfg;
        if (!$this->enabled()) throw new \RuntimeException('transcription is off: set GEMINI_API_KEY (or TALIXIO_TRANSCRIBE=openai with an OpenAI key)');
        $path = $this->compress($file['path']);
        try {
            return match ($c->transcribe) {
                'gemini' => $this->gemini($path, $meeting),
                'openai' => $this->openai($path),
                default => throw new \RuntimeException("unknown TALIXIO_TRANSCRIBE {$c->transcribe}"),
            };
        } finally {
            if ($path !== $file['path']) @unlink($path);
        }
    }

    /** Mono Opus at 24 kbit/s: about 11 MB per hour, accepted by both services. */
    private function compress(string $path): string
    {
        if (trim((string)shell_exec('command -v ffmpeg 2>/dev/null')) === '') return $path;
        $out = tempnam(sys_get_temp_dir(), 'talixio-audio') . '.ogg';
        exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($path) . ' -vn -ac 1 -ar 16000 -c:a libopus -b:a 24k ' . escapeshellarg($out) . ' 2>&1', $o, $code);
        return $code === 0 && filesize($out) > 0 ? $out : $path;
    }

    private function prompt(bool $meeting): string
    {
        return $meeting
            ? "Transcribe this recording verbatim in its original language. Start each speaker turn on a new line with [mm:ss] and the speaker: use names if they are said, otherwise Speaker 1, Speaker 2... Do not summarise. Output only the transcript."
            : "Transcribe this voice message verbatim in its original language. Output only the text, without timestamps or speaker labels.";
    }

    private function model(): string
    {
        $c = $this->app->cfg;
        if ($c->transcribeModel !== '') return $c->transcribeModel;
        return $c->provider === 'gemini' && $c->tier('standard')->model !== '' ? $c->tier('standard')->model : GeminiModels::FALLBACK['standard'];
    }

    private function gemini(string $path, bool $meeting): string
    {
        $c = $this->app->cfg;
        $mime = str_ends_with($path, '.ogg') ? 'audio/ogg' : ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'audio/mpeg');
        if ($mime === 'video/webm' || $mime === 'audio/webm') $mime = 'audio/webm';
        $key = ['x-goog-api-key: ' . $c->geminiKey];
        $uploaded = null;
        if (filesize($path) <= 14_000_000) {
            $audio = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode((string)file_get_contents($path))]];
        } else {
            $uploaded = $this->geminiUpload($path, $mime);
            $audio = ['file_data' => ['mime_type' => $mime, 'file_uri' => $uploaded['uri']]];
        }
        try {
            $model = $this->model();
            $r = Http::json("{$c->geminiBaseUrl}/models/$model:generateContent", [
                'contents' => [['parts' => [$audio, ['text' => $this->prompt($meeting)]]]],
                'generationConfig' => ['maxOutputTokens' => 65536],
            ], $key, 900);
            $u = $r['usageMetadata'] ?? [];
            $usage = ['model' => $model, 'in' => (int)($u['promptTokenCount'] ?? 0), 'out' => (int)($u['candidatesTokenCount'] ?? 0) + (int)($u['thoughtsTokenCount'] ?? 0), 'cache_read' => 0, 'cache_write' => 0, 'cost' => null];
            $this->app->store->addUsage($usage, $this->app->pricing()->cost($usage), 'transcription', 'fast', null);
            $text = trim(implode('', array_column($r['candidates'][0]['content']['parts'] ?? [], 'text')));
            if ($text === '') throw new \RuntimeException('Gemini returned no transcript (' . ($r['candidates'][0]['finishReason'] ?? 'unknown reason') . ')');
            return $text;
        } finally {
            if ($uploaded) { try { Http::request('DELETE', "{$c->geminiBaseUrl}/{$uploaded['name']}", $key, null, 20); } catch (\Throwable) {} }
        }
    }

    /** Gemini Files API resumable upload for recordings too large to send inline. */
    private function geminiUpload(string $path, string $mime): array
    {
        $c = $this->app->cfg;
        $size = filesize($path);
        $uploadBase = preg_replace('#/(v1\w*)$#', '/upload/$1', $c->geminiBaseUrl);
        [$code, $body, $h] = Http::request('POST', "$uploadBase/files", [
            'x-goog-api-key: ' . $c->geminiKey, 'X-Goog-Upload-Protocol: resumable', 'X-Goog-Upload-Command: start',
            "X-Goog-Upload-Header-Content-Length: $size", "X-Goog-Upload-Header-Content-Type: $mime", 'Content-Type: application/json',
        ], json_encode(['file' => ['display_name' => basename($path)]]), 60);
        $url = $h['x-goog-upload-url'] ?? throw new \RuntimeException("Gemini upload start failed ($code): " . mb_substr($body, 0, 200));
        [$code, $body] = Http::request('POST', $url, ["Content-Length: $size", 'X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize'],
            (string)file_get_contents($path), 900);
        $file = json_decode($body, true)['file'] ?? throw new \RuntimeException("Gemini upload failed ($code): " . mb_substr($body, 0, 200));
        for ($i = 0; $i < 60 && ($file['state'] ?? 'ACTIVE') === 'PROCESSING'; $i++) {
            sleep(2);
            [, $b] = Http::request('GET', "{$c->geminiBaseUrl}/{$file['name']}", ['x-goog-api-key: ' . $c->geminiKey], null, 20);
            $file = json_decode($b, true) ?: $file;
        }
        if (($file['state'] ?? '') === 'FAILED') throw new \RuntimeException('Gemini could not process the audio file');
        return $file;
    }

    private function openai(string $path): string
    {
        $c = $this->app->cfg;
        if (filesize($path) > 25_000_000) throw new \RuntimeException('recording too large for OpenAI transcription (25 MB max); use Gemini');
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c->openaiKey],
            CURLOPT_POSTFIELDS => ['model' => $c->transcribeModel ?: 'gpt-4o-transcribe', 'file' => new \CURLFile($path)]]);
        $out = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $text = json_decode($out, true)['text'] ?? null;
        if ($code >= 400 || $text === null) throw new \RuntimeException("OpenAI transcription failed ($code): " . mb_substr($out, 0, 200));
        return trim($text);
    }
}
