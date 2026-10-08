<?php
declare(strict_types=1);
namespace Talixio;

/** Pushes messages the assistant sends on its own (scheduled tasks, heartbeat) to your phone. */
final class Notifier
{
    public function __construct(private Config $cfg) {}

    public function notify(string $title, string $text): void
    {
        if ($this->cfg->ntfyUrl !== '') {
            try {
                $h = ['Title: ' . mb_encode_mimeheader($title, 'UTF-8'), 'Markdown: yes'];
                if ($this->cfg->ntfyToken !== '') $h[] = 'Authorization: Bearer ' . $this->cfg->ntfyToken;
                Http::request('POST', $this->cfg->ntfyUrl, $h, mb_substr($text, 0, 3500), 15);
            } catch (\Throwable $e) { Log::warn("ntfy: {$e->getMessage()}"); }
        }
        if ($this->cfg->telegramToken !== '' && $this->cfg->telegramAllowed) {
            try { (new Telegram($this->cfg, null))->send($this->cfg->telegramAllowed[0], $text); }
            catch (\Throwable $e) { Log::warn("telegram notify: {$e->getMessage()}"); }
        }
    }
}
