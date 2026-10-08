<?php
declare(strict_types=1);
namespace Talixio;

/** Delivers messages the assistant starts (scheduled tasks, heartbeat, job reports) to every configured channel in TALIXIO_NOTIFY. */
final class Notifier
{
    public function __construct(private App $app) {}

    /** @return array<string,string> channel => "sent" | "not configured" | error */
    public function channels(): array
    {
        $c = $this->app->cfg;
        return [
            'webpush' => $this->app->webPush()->count() > 0 ? 'on' : 'no device subscribed',
            'ntfy' => $c->ntfyUrl !== '' ? 'on' : 'not configured',
            'telegram' => $c->telegramToken !== '' && $c->telegramAllowed ? 'on' : 'not configured',
            'email' => $this->app->email()->canSend() && $c->emailTo !== '' ? 'on' : 'not configured',
            'signal' => $this->app->signal()->enabled() ? 'on' : 'not configured',
        ];
    }

    public function notify(string $title, string $text): array
    {
        $c = $this->app->cfg;
        $results = [];
        foreach ($this->channels() as $ch => $state) {
            if (!in_array($ch, $c->notify, true) || $state !== 'on') { $results[$ch] = in_array($ch, $c->notify, true) ? $state : 'off (TALIXIO_NOTIFY)'; continue; }
            try {
                match ($ch) {
                    'webpush' => $this->app->webPush()->notify($title, $text),
                    'ntfy' => $this->ntfy($title, $text),
                    'telegram' => (new Telegram($c, null))->send($c->telegramAllowed[0], $text),
                    'email' => $this->app->email()->notify($title, $text),
                    'signal' => $this->app->signal()->notify($title, $text),
                };
                $results[$ch] = 'sent';
                $this->app->store->set("channel_error_$ch", '');
            } catch (\Throwable $e) {
                $results[$ch] = 'error: ' . $e->getMessage();
                $this->app->store->set("channel_error_$ch", date('Y-m-d H:i') . ' ' . $e->getMessage());
                Log::warn("notify $ch: {$e->getMessage()}");
            }
        }
        return $results;
    }

    private function ntfy(string $title, string $text): void
    {
        $h = ['Title: ' . mb_encode_mimeheader($title, 'UTF-8'), 'Markdown: yes'];
        if ($this->app->cfg->ntfyToken !== '') $h[] = 'Authorization: Bearer ' . $this->app->cfg->ntfyToken;
        [$code] = Http::request('POST', $this->app->cfg->ntfyUrl, $h, mb_substr($text, 0, 3500), 15);
        if ($code >= 400) throw new \RuntimeException("ntfy HTTP $code");
    }
}
