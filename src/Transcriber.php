<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Speech to text: Gemini (long recordings, speaker labels), OpenAI, or local with whisper.cpp (nothing leaves the server;
 * slower, no speaker labels). Audio is compressed with ffmpeg first.
 */
final class Transcriber
{
    public function __construct(private App $app) {}

    public function enabled(): bool { return $this->app->mainCfg->transcribe !== 'off'; }

    public function transcribe(array $file, bool $meeting): string
    {
        $c = $this->app->mainCfg;
        if (!$this->enabled()) throw new \RuntimeException('transcription is off: set GEMINI_API_KEY (or TALIXIO_TRANSCRIBE=openai with an OpenAI key, or local with whisper.cpp)');
        if ($c->transcribe === 'local') { Privacy::local('Transcription (whisper.cpp)'); return $this->local($file['path'], $meeting); }
        $path = $this->compress($file['path']);
        try {
            return match ($c->transcribe) {
                'gemini' => Privacy::as('transcription', fn() => $this->gemini($path, $meeting)),
                'openai' => Privacy::as('transcription', fn() => $this->openai($path, 'https://api.openai.com/v1', $c->openaiKey, $c->transcribeModel ?: 'gpt-4o-transcribe', 'OpenAI')),
                'sovereign' => Privacy::as('transcription', fn() => $this->openai($path, $c->sovereignUrl, $c->sovereignKey,
                    preg_match('/whisper|voxtral/i', $c->transcribeModel) ? $c->transcribeModel : Sovereign::info($c->sovereign)['transcribe'], Sovereign::info($c->sovereign)['name'])),
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
        $c = $this->app->mainCfg;
        if (str_starts_with($c->transcribeModel, 'gemini')) return $c->transcribeModel; // a whisper.cpp model name left from local transcription is not one
        return $c->provider === 'gemini' && $c->tier('standard')->model !== '' ? $c->tier('standard')->model : GeminiModels::FALLBACK['standard'];
    }

    private function gemini(string $path, bool $meeting): string
    {
        $c = $this->app->mainCfg;
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
            $gen = ['maxOutputTokens' => 65536];
            // writing down what is said needs no thinking, and thinking tokens are billed as output
            if (preg_match('/^gemini-2\.5-flash/', $model)) $gen['thinkingConfig'] = ['thinkingBudget' => 0];
            elseif (preg_match('/^gemini-[3-9]/', $model)) $gen['thinkingConfig'] = ['thinkingLevel' => 'low'];
            $r = Http::json("{$c->geminiBaseUrl}/models/$model:generateContent", [
                'contents' => [['parts' => [$audio, ['text' => $this->prompt($meeting)]]]],
                'generationConfig' => $gen,
            ], $key, 900);
            $u = $r['usageMetadata'] ?? [];
            $usage = ['model' => $model, 'in' => (int)($u['promptTokenCount'] ?? 0), 'out' => (int)($u['candidatesTokenCount'] ?? 0) + (int)($u['thoughtsTokenCount'] ?? 0), 'cache_read' => 0, 'cache_write' => 0, 'cost' => null];
            $this->app->store->addUsage($usage, $this->app->pricing()->cost($usage), 'transcription', 'fast', null, '-');
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
        $c = $this->app->mainCfg;
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

    /** The OpenAI audio API, also spoken by the European providers. */
    private function openai(string $path, string $base, string $key, string $model, string $name): string
    {
        if (filesize($path) > 25_000_000) throw new \RuntimeException("recording too large for $name transcription (25 MB max); use Gemini or local transcription");
        $url = rtrim($base, '/') . '/audio/transcriptions';
        Http::check($url, (int)filesize($path));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900,
            CURLOPT_HTTPHEADER => $key !== '' ? ['Authorization: Bearer ' . $key] : [],
            CURLOPT_POSTFIELDS => ['model' => $model, 'file' => new \CURLFile($path)]]);
        $out = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $text = json_decode($out, true)['text'] ?? null;
        if ($code >= 400 || $text === null) throw new \RuntimeException("$name transcription failed ($code): " . mb_substr($out, 0, 200));
        return trim($text);
    }

    /** whisper.cpp's command and model file: TALIXIO_TRANSCRIBE_MODEL is a model name (installed by install.sh --with-whisper) or a path. */
    public static function whisper(Config $c): array
    {
        $bin = trim((string)shell_exec('command -v whisper-cli 2>/dev/null')) ?: (is_file(self::WHISPER_DIR . '/build/bin/whisper-cli') ? self::WHISPER_DIR . '/build/bin/whisper-cli' : '');
        $m = $c->transcribeModel !== '' ? $c->transcribeModel : self::WHISPER_MODEL;
        $model = str_contains($m, '/') ? $m : self::WHISPER_DIR . "/models/ggml-$m.bin";
        if ($c->transcribeModel === '' && !is_file($model)) $model = (glob(self::WHISPER_DIR . '/models/ggml-*.bin') ?: [$model])[0]; // another model was installed
        return [$bin, $model];
    }

    /** Whether local transcription can run: the program and a model. */
    public static function whisperReady(): bool
    {
        return is_file(self::WHISPER_DIR . '/build/bin/whisper-cli') && glob(self::WHISPER_DIR . '/models/ggml-*.bin');
    }

    /** How to install it, where this runs. */
    public static function whisperHint(): string
    {
        return getenv('TALIXIO_MANAGED') === '1' ? 'the administrator runs deploy/docker/install-stack.sh, which downloads the model' : 'sudo ./install.sh --with-whisper';
    }

    /** Cores this process may use: the container's CPU limit when there is one, else the machine's. */
    private static function cores(): int
    {
        $n = (int)trim((string)shell_exec('nproc 2>/dev/null')) ?: 2;
        if (preg_match('/^(\d+) (\d+)/', (string)@file_get_contents('/sys/fs/cgroup/cpu.max'), $m) && (int)$m[2] > 0) $n = min($n, (int)ceil((int)$m[1] / (int)$m[2]));
        return max(1, min(8, $n));
    }

    public const WHISPER_DIR = '/opt/whisper.cpp';
    public const WHISPER_MODEL = 'large-v3-turbo-q5_0'; // multilingual, good French, about 1 GB of memory; "small" is faster, "medium" in between

    /** On this server with whisper.cpp: 16 kHz mono WAV in, JSON segments out. Long: about a third to two thirds of the recording's length on 4 cores. */
    private function local(string $path, bool $meeting): string
    {
        [$bin, $model] = self::whisper($this->app->mainCfg);
        if ($bin === '') throw new \RuntimeException('local transcription: whisper.cpp is not installed (' . self::whisperHint() . ')');
        if (!is_file($model)) throw new \RuntimeException("local transcription: model file not found: $model (" . self::whisperHint() . ')');
        $tmp = tempnam(sys_get_temp_dir(), 'talixio-whisper');
        $wav = "$tmp.wav";
        try {
            exec('ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($path) . ' -vn -ac 1 -ar 16000 -c:a pcm_s16le ' . escapeshellarg($wav) . ' 2>&1', $o, $code);
            if ($code !== 0 || !is_file($wav)) throw new \RuntimeException('local transcription: ffmpeg could not read the audio: ' . mb_substr(implode(' ', $o), 0, 200));
            $threads = self::cores();
            $cmd = 'nice -n 10 ' . escapeshellarg($bin) . ' -m ' . escapeshellarg($model) . ' -f ' . escapeshellarg($wav) . " -l auto -t $threads -np -oj -of " . escapeshellarg($tmp) . ' 2>&1';
            $o = []; exec($cmd, $o, $code);
            $json = json_decode((string)@file_get_contents("$tmp.json"), true);
            if ($code === 137) throw new \RuntimeException('local transcription: not enough memory for the model ' . basename($model) . ' (about 1 GB): give this assistant 2 GB, or choose the model "small" in Settings');
            if ($code !== 0 || !is_array($json)) throw new \RuntimeException("local transcription failed ($code): " . mb_substr(implode(' ', array_slice($o, -5)), 0, 300));
            $lines = [];
            foreach ($json['transcription'] ?? [] as $seg) {
                $text = trim((string)($seg['text'] ?? ''));
                if ($text === '' || preg_match('/^[\[(].*[\])]$/u', $text)) continue; // [Music], (silence)
                $ms = (int)($seg['offsets']['from'] ?? 0);
                $lines[] = $meeting ? sprintf('[%02d:%02d] %s', intdiv($ms, 60000), intdiv($ms, 1000) % 60, $text) : $text;
            }
            $out = trim(implode($meeting ? "\n" : ' ', $lines));
            if ($out === '') throw new \RuntimeException('local transcription: nothing was heard in the recording');
            $this->app->store->addUsage(['model' => 'whisper.cpp ' . basename($model), 'in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cost' => 0.0], 0.0, 'transcription', 'fast', null, '-');
            return $out;
        } finally {
            foreach ([$tmp, $wav, "$tmp.json"] as $f) @unlink($f);
        }
    }
}
