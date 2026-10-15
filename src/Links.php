<?php
declare(strict_types=1);
namespace Talixio;

/**
 * One sign-in for a person's assistants (their work and private ones, say). The admin page links them; the server
 * hands each one its own key and the keys of the assistants it opens (TALIXIO_SSO_KEY, TALIXIO_LINKS). To open
 * another, this assistant signs a short ticket with that one's key; the other checks it, once, within a minute,
 * and opens a session. Assistants never call each other: the browser carries the ticket.
 */
final class Links
{
    public const TTL = 60;

    public function __construct(private App $app) {}

    /** This assistant's identifier on the server ('' outside a server with several people). */
    public static function self(): string { return (string)getenv('TALIXIO_INSTANCE'); }

    private static function all(): array
    {
        $l = json_decode((string)getenv('TALIXIO_LINKS'), true);
        return is_array($l) ? array_values(array_filter($l, fn($x) => is_array($x) && Fleet::validId((string)($x['id'] ?? '')) && ($x['key'] ?? '') !== '')) : [];
    }

    /** The assistants this one opens: name and address, never a key. */
    public function list(): array
    {
        return array_map(fn($x) => ['id' => $x['id'], 'name' => (string)($x['name'] ?? $x['id']), 'url' => (string)($x['url'] ?? '')], self::all());
    }

    /** This assistant as the switcher shows it. */
    public function current(): array
    {
        return ['id' => self::self(), 'name' => (string)getenv('TALIXIO_INSTANCE_NAME') ?: $this->app->cfg->name];
    }

    /** The address that opens $to signed in, valid once for a minute. */
    public function ticket(string $to): string
    {
        $l = current(array_filter(self::all(), fn($x) => $x['id'] === $to));
        if (!$l || self::self() === '') throw new \InvalidArgumentException('That assistant is not linked to this one.');
        $payload = self::b64(json_encode(['f' => self::self(), 't' => $to, 'e' => time() + self::TTL, 'n' => bin2hex(random_bytes(12))]));
        return rtrim((string)$l['url'], '/') . '/sso?t=' . $payload . '.' . self::b64(hash_hmac('sha256', $payload, (string)$l['key'], true));
    }

    /** The assistant a ticket comes from, or null: wrong key, another target, expired, already used, or no longer linked. */
    public function verify(string $ticket): ?string
    {
        $key = (string)getenv('TALIXIO_SSO_KEY');
        if ($key === '' || self::self() === '' || !preg_match('/^([A-Za-z0-9_-]{20,400})\.([A-Za-z0-9_-]{43})$/', $ticket, $m)) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', $m[1], $key, true)), $m[2])) return null;
        $p = json_decode((string)base64_decode(strtr($m[1], '-_', '+/')), true);
        if (!is_array($p) || ($p['t'] ?? '') !== self::self() || !is_int($p['e'] ?? null) || $p['e'] < time() || $p['e'] > time() + self::TTL + 5) return null;
        $from = (string)($p['f'] ?? '');
        if (!in_array($from, array_column(self::all(), 'id'), true)) return null;
        $auth = new Auth($this->app); $nonce = 'sso:' . preg_replace('/[^0-9a-f]/', '', (string)($p['n'] ?? ''));
        if ($nonce === 'sso:' || $auth->count($nonce, 3600) > 0) return null; // each ticket opens one session
        $auth->hit($nonce);
        return $from;
    }

    private static function b64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
}
