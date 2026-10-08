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

    /** One long-poll round (up to $timeout seconds). */
    public function poll(int $timeout): void
    {
        $store = $this->app->store;
        $offset = (int)$store->get('telegram_offset', '0');
        [, $body] = Http::request('GET', "{$this->api}/getUpdates?timeout=$timeout&offset=$offset", [], null, $timeout + 15);
        foreach ((json_decode($body, true)['result'] ?? []) as $u) {
            $store->set('telegram_offset', (string)($u['update_id'] + 1));
            $chat = $u['message']['chat']['id'] ?? null;
            $text = $u['message']['text'] ?? null;
            if ($chat === null || $text === null) continue;
            if (!in_array($chat, $this->cfg->telegramAllowed, true)) {
                Log::warn("telegram: ignored chat $chat (add it to TALIXIO_TELEGRAM_ALLOWED_IDS)");
                $this->send($chat, "This assistant is private. Your chat id is $chat.");
                continue;
            }
            if ($text === '/start') { $this->send($chat, "Hi, I'm {$this->cfg->name}. Ask me anything."); continue; }
            $this->send($chat, $this->app->agent()->chat('telegram', $text));
        }
    }
}
