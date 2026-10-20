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
        $url = '#chat/' . $sp['slug']; // relative to the app (it may live under a prefix)
        $unread = $this->record($title, $text, $space, $url);
        if ($c->notifyNeutral || $this->app->privacy()->spaceLevel($space) !== 'internal') { // the content stays in the app
            $title = $c->name;
            $text = I18n::t($this->app, 'New message in {space}', ['space' => $space ? $sp['name'] : 'General']);
        }
        if ($space) $title = "{$sp['name']} · $title";
        $plain = $space && !str_contains($text, $sp['name']) ? "[{$sp['name']}] $text" : $text; // chat apps: say which space it comes from
        foreach ($this->channels() as $ch => $state) {
            if (!in_array($ch, $c->notify, true) || $state !== 'on') { $results[$ch] = in_array($ch, $c->notify, true) ? $state : 'off (TALIXIO_NOTIFY)'; continue; }
            try {
                Privacy::as('notify', fn() => match ($ch) {
                    'webpush' => $this->app->webPush()->notify($title, $text, $url, $unread),
                    'ntfy' => $this->ntfy($title, $text),
                    'telegram' => (new Telegram($c, null))->send($c->telegramAllowed[0], $plain),
                    'email' => $this->app->email()->notify($title, $text, $space ? $sp : null, $url),
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

    /** A notification kept in the app's inbox only (not sent to chat apps or e-mail). */
    public function inApp(string $title, string $text, string $url, int $space = 0): int
    {
        return $this->record($title, $text, $space, $url);
    }

    /** The in-app inbox keeps every notification, with its full text (it stays in the app); a secret space's only says where to look. Returns the unread count. */
    private function record(string $title, string $text, int $space, string $url): int
    {
        try {
            if ($space && $this->app->privacy()->spaceLevel($space) === 'secret') $text = I18n::t($this->app, 'New message in {space}', ['space' => $this->app->spaces()->get($space)['name'] ?? '']);
            $this->app->store->q('INSERT INTO notifications(title, body, url, space, created) VALUES (?, ?, ?, ?, ?)', [mb_substr($title, 0, 300), mb_substr($text, 0, 4000), $url, $space, time()]);
            $this->app->store->q('DELETE FROM notifications WHERE created < ? OR id <= (SELECT id FROM notifications ORDER BY id DESC LIMIT 1 OFFSET 200)', [time() - 60 * 86400]);
        } catch (\Throwable $e) {
            Log::warn("notification inbox: {$e->getMessage()}");
        }
        return $this->unread();
    }

    /** @return list<array{id:int,title:string,body:string,url:string,space:int,created:int,read_at:?int}> newest first */
    public function inbox(int $limit = 200): array
    {
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'url' => $r['url'], 'space' => (int)$r['space'],
            'created' => (int)$r['created'], 'read_at' => $r['read_at'] === null ? null : (int)$r['read_at']],
            $this->app->store->q('SELECT * FROM notifications ORDER BY id DESC LIMIT ?', [$limit])->fetchAll());
    }

    public function unread(): int { return (int)$this->app->store->q('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(); }

    /** Marks one notification read, or all of them ($id null). */
    public function markRead(?int $id = null): int
    {
        return $id === null ? $this->app->store->q('UPDATE notifications SET read_at = ? WHERE read_at IS NULL', [time()])->rowCount()
            : $this->app->store->q('UPDATE notifications SET read_at = ? WHERE id = ? AND read_at IS NULL', [time(), $id])->rowCount();
    }

    public function clear(): int { return $this->app->store->q('DELETE FROM notifications')->rowCount(); }

    private function ntfy(string $title, string $text): void
    {
        $h = ['Title: ' . mb_encode_mimeheader($title, 'UTF-8'), 'Markdown: yes'];
        if ($this->app->cfg->ntfyToken !== '') $h[] = 'Authorization: Bearer ' . $this->app->cfg->ntfyToken;
        [$code] = Http::request('POST', $this->app->cfg->ntfyUrl, $h, mb_substr($text, 0, 3500), 15);
        if ($code >= 400) throw new \RuntimeException("ntfy HTTP $code");
    }
}
