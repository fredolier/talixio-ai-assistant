<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The web app's side of updates. The work itself is done as root by talixio-update.service
 * (deploy/talixio-update.sh), which the app triggers by creating a request file in the data folder.
 */
final class Updater
{
    public function __construct(private App $app) {}

    public function installed(): bool { return is_file('/etc/talixio/install.conf') && is_file($this->appDir() . '/VERSION.json'); }
    private function appDir(): string { return dirname(__DIR__); }

    public function version(): array { return Version::get(); }
    /** Root writes the status in its own folder (never in the data folder, which this user owns: a link there could make root overwrite any file). */
    public const STATE_DIR = '/var/lib/talixio-update';

    public function status(): array
    {
        $f = is_file(self::STATE_DIR . '/status.json') ? self::STATE_DIR . '/status.json' : $this->app->cfg->dataDir . '/update-status.json';
        return json_decode((string)@file_get_contents($f), true) ?: ['state' => 'unknown'];
    }

    /** $what: check | update | rollback */
    public function request(string $what): void
    {
        if (!in_array($what, ['check', 'update', 'rollback'], true)) throw new \InvalidArgumentException('unknown request');
        if (getenv('TALIXIO_MANAGED')) throw new \RuntimeException('This assistant is updated by the administrator of the server (new image for everyone).');
        if (!$this->installed()) throw new \RuntimeException('Updates work on an installation made with git clone + install.sh.');
        $st = $this->status();
        if (in_array($st['state'] ?? '', ['updating', 'checking'], true) && time() - (int)($st['updated_at'] ?? 0) < 1800) throw new \RuntimeException('An update is already running.');
        file_put_contents($this->app->cfg->dataDir . "/$what.request", (string)time());
    }

    /** Worker tick: tell the user once per new version. */
    public function notifyIfNew(): void
    {
        $st = $this->status();
        if (($st['state'] ?? '') !== 'available' || empty($st['latest'])) return;
        if ($this->app->store->get('update_notified') === $st['latest']) return;
        $this->app->store->set('update_notified', $st['latest']);
        $list = implode("\n", array_map(fn($c) => "- {$c['subject']}", array_slice($st['commits'] ?? [], 0, 8)));
        $this->app->notifier()->notify('Update available', ($st['message'] ?? 'A new version is available.') . "\n$list\n\nOpen Settings to install it" . ($this->app->cfg->autoUpdate ? ' (or wait: it installs itself tonight).' : '.'));
    }
}
