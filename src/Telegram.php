<?php
declare(strict_types=1);
namespace Talixio;

/** Telegram bot via long polling: no public URL needed. Only allowlisted chat ids are answered. */
final class Telegram
{
    private string $api;

    public function __construct(private Config $cfg, private ?App $app)
    {
        $this->api = "https://api.telegram.org/bot{$cfg->telegramToken}";
    }

    public function send(int $chat, string $text): void
    {
        foreach (mb_str_split($text, 4000) as $part) {
            Http::json("{$this->api}/sendMessage", ['chat_id' => $chat, 'text' => $part], [], 30);
        }
    }

    /** Download a Telegram file (bots can fetch up to 20 MB) into Talixio's files. */
    private function download(array $media, string $fallbackName): int
    {
        if (($media['file_size'] ?? 0) > 20_000_000) throw new \RuntimeException('Telegram bots can only download files up to 20 MB; upload big recordings in the web app');
        [, $body] = Http::request('GET', "{$this->api}/getFile?file_id=" . rawurlencode($media['file_id']), [], null, 30);
        $path = json_decode($body, true)['result']['file_path'] ?? throw new \RuntimeException('Telegram: file not available');
        $tmp = tempnam(sys_get_temp_dir(), 'tg');
        [$code, $data] = Http::request('GET', "https://api.telegram.org/file/bot{$this->cfg->telegramToken}/$path", [], null, 120);
        if ($code >= 400) throw new \RuntimeException("Telegram download failed ($code)");
        file_put_contents($tmp, $data);
        return $this->app->files()->save($tmp, $media['file_name'] ?? basename($path) ?: $fallbackName, $media['mime_type'] ?? '');
    }

    /** One long-poll round (up to $timeout seconds). */
    public function poll(int $timeout): void
    {
        $store = $this->app->store;
        $offset = (int)$store->get('telegram_offset', '0');
        [, $body] = Http::request('GET', "{$this->api}/getUpdates?timeout=$timeout&offset=$offset", [], null, $timeout + 15);
        foreach ((json_decode($body, true)['result'] ?? []) as $u) {
            $store->set('telegram_offset', (string)($u['update_id'] + 1));
            $m = $u['message'] ?? [];
            $chat = $m['chat']['id'] ?? null;
            $text = $m['text'] ?? $m['caption'] ?? '';
            $media = $m['voice'] ?? $m['audio'] ?? $m['video_note'] ?? $m['document'] ?? (isset($m['photo']) ? end($m['photo']) : null);
            if ($chat === null || ($text === '' && !$media)) continue;
            if (!in_array($chat, $this->cfg->telegramAllowed, true)) {
                Log::warn("telegram: ignored chat $chat (add it to TALIXIO_TELEGRAM_ALLOWED_IDS)");
                $this->send($chat, "This assistant is private. Your chat id is $chat.");
                continue;
            }
            if ($text === '/start') { $this->send($chat, "Hi, I'm {$this->cfg->name}. Ask me anything."); continue; }
            try {
                $files = $media ? [$this->download($media, isset($m['photo']) ? 'photo.jpg' : ($m['voice'] ?? null ? 'voice.ogg' : 'file'))] : [];
                $this->send($chat, $this->app->agent()->incoming('telegram', $text, $files));
            } catch (\Throwable $e) {
                $this->send($chat, "I couldn't process that: " . Web::internalError($e, 'telegram')); // logged there; no file paths or SQL sent out
            }
        }
    }
}
