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

    /** $space: the space the message belongs to (named in the title; the app notification opens it). */
    public function notify(string $title, string $text, int $space = 0): array
    {
        $c = $this->app->cfg;
        $results = [];
        $sp = $this->app->spaces()->get($space) ?? $this->app->spaces()->general();
        if ($c->notifyNeutral || $this->app->privacy()->spaceLevel($space) !== 'internal') { // the content stays in the app
            $title = $c->name;
            $text = I18n::t($this->app, 'New message in {space}', ['space' => $space ? $sp['name'] : 'General']);
        }
        if ($space) $title = "{$sp['name']} · $title";
        $url = '#chat/' . $sp['slug']; // relative to the app (it may live under a prefix)
        $plain = $space && !str_contains($text, $sp['name']) ? "[{$sp['name']}] $text" : $text; // chat apps: say which space it comes from
        foreach ($this->channels() as $ch => $state) {
            if (!in_array($ch, $c->notify, true) || $state !== 'on') { $results[$ch] = in_array($ch, $c->notify, true) ? $state : 'off (TALIXIO_NOTIFY)'; continue; }
            try {
                Privacy::as('notify', fn() => match ($ch) {
                    'webpush' => $this->app->webPush()->notify($title, $text, $url),
                    'ntfy' => $this->ntfy($title, $text),
                    'telegram' => (new Telegram($c, null))->send($c->telegramAllowed[0], $plain),
                    'email' => $this->app->email()->notify($title, $text),
                    'signal' => $this->app->signal()->notify($title, $plain),
                });
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
