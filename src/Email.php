<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Email channel: notifications by SMTP, and chat by email (IMAP polling of the assistant's mailbox).
 * Only allowlisted senders get answers, and by default only when the receiving server confirmed
 * the sender (SPF, DKIM or DMARC pass in Authentication-Results), so a forged From is ignored.
 */
final class Email
{
    public function __construct(private App $app) {}

    public function canSend(): bool { return $this->app->cfg->smtpHost !== '' && $this->app->cfg->emailFrom !== ''; }
    public function canReceive(): bool { return $this->app->cfg->imapHost !== '' && $this->app->cfg->emailAllowed !== []; }

    /** The assistant's own mail servers, signed in with the password or the connected Microsoft account. */
    public function smtp(): Smtp
    {
        $c = $this->app->cfg;
        [$user, $token] = Microsoft::mailLogin($this->app, 'email', $c->smtpUser, $c->smtpHost) ?? [$c->smtpUser, null];
        return new Smtp($c->smtpHost, $c->smtpPort, $c->smtpSecurity, $user, $c->smtpPassword, $token);
    }

    public function imap(): Imap
    {
        $c = $this->app->cfg;
        [$user, $token] = Microsoft::mailLogin($this->app, 'email', $c->imapUser, $c->imapHost) ?? [$c->imapUser, null];
        return new Imap($c->imapHost, $c->imapPort, $user, $c->imapPassword, $c->imapFolder, $c->imapSecurity, $token);
    }

    /** $page: MailTemplate::page options (title, space, color, url); the mail is framed as the assistant's. */
    public function send(string $to, string $subject, string $markdown, array $headers = [], array $page = []): string
    {
        return $this->smtp()->send($this->app->cfg->emailFrom, $to, $subject, $markdown, $headers, [], $this->html($markdown, $page));
    }

    /** A mail with extra parts (a meeting invitation) to several people, written by the user (not marked automatic). */
    public function sendParts(array $to, string $subject, string $markdown, array $parts, array $headers = []): void
    {
        $this->smtp()->send($this->app->cfg->emailFrom, $to[0], $subject, $markdown, ['To' => implode(', ', $to), 'Auto-Submitted' => null] + $headers, array_slice($to, 1), null, $parts);
    }

    /** $space: the space's row (name, color), when the message belongs to one; $url: the page to open, relative to the app. */
    public function notify(string $title, string $text, ?array $space = null, string $url = ''): void
    {
        $to = $this->app->cfg->emailTo;
        if ($this->canSend() && $to !== '') $this->send($to, "[{$this->app->cfg->name}] $title", $text, [],
            ['title' => $title, 'space' => $space['name'] ?? '', 'color' => $space['color'] ?? '', 'url' => $url]);
    }

    /** The HTML of a mail from the assistant. */
    public function html(string $markdown, array $page = []): string
    {
        $app = $this->app;
        $base = $app->shares()->baseUrl();
        return MailTemplate::page($markdown, $page + [
            'app' => $app->cfg->name, 'base' => $base, 'url' => '',
            'button' => I18n::t($app, 'Open {name}', ['name' => $app->cfg->name]),
            'footer' => I18n::t($app, 'Sent by {name}, your assistant.', ['name' => $app->cfg->name]),
        ]);
    }

    /** Worker tick: answer new emails. Returns how many were answered. */
    public function poll(?Imap $imap = null): int
    {
        if (!$this->canReceive() || !$this->canSend()) return 0;
        $c = $this->app->cfg;
        $imap ??= $this->imap();
        $raw = $imap->fetchUnseen();
        $answered = 0;
        foreach ($raw as $uid => $msg) {
            $imap->markSeen([$uid]); // first, so a failure never makes us answer twice
            try {
                if ($this->handle($msg)) $answered++;
            } catch (\Throwable $e) {
                Log::error("email $uid: {$e->getMessage()}");
            }
        }
        return $answered;
    }

    /** Process one raw message. Returns true if it was answered. */
    public function handle(string $raw): bool
    {
        $c = $this->app->cfg;
        $m = Mime::parse($raw);
        $h = $m['headers'];
        $from = strtolower(Smtp::address(Mime::decodeHeader($h['from'] ?? '')));
        if (!in_array($from, $c->emailAllowed, true)) { Log::warn("email: ignored sender $from (not in TALIXIO_EMAIL_ALLOWED)"); return false; }
        if (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no') return false; // never answer robots or ourselves
        if ($c->emailRequireAuth && !self::authenticated($h['authentication-results'] ?? '', $from, $c->emailAuthServ)) {
            Log::warn("email: ignored $from: no DMARC or DKIM pass in Authentication-Results (set TALIXIO_EMAIL_REQUIRE_AUTH=0 if your server doesn't add it)");
            return false;
        }
        $subject = trim(Mime::decodeHeader($h['subject'] ?? ''));
        $body = Mime::stripReply($m['text']);
        $isReply = preg_match('/^(re|aw|tr|fwd?)\s*:/i', $subject);
        $text = trim(($isReply || $subject === '' ? '' : "Subject: $subject\n\n") . $body);
        if ($text === '') return false;
        $reply = $this->app->agent()->chat('email', mb_substr($text, 0, 20000));
        $refs = trim(($h['references'] ?? '') . ' ' . ($h['message-id'] ?? ''));
        $this->send($from, $isReply ? $subject : 'Re: ' . ($subject ?: $c->name), $reply,
            array_filter(['In-Reply-To' => $h['message-id'] ?? '', 'References' => $refs]), ['url' => '#chat']);
        return true;
    }

    /**
     * True if the receiving server vouches for the sender's domain: DMARC pass, or a DKIM signature of that domain.
     * Only the topmost Authentication-Results counts (the one our own server added; the sender can write any below
     * it), and only from $authserv when set. SPF alone is not enough: it checks the envelope, not the From line.
     */
    public static function authenticated(string $results, string $from, string $authserv = ''): bool
    {
        $domain = substr(strrchr($from, '@') ?: '', 1);
        $results = strtolower(trim(strtok($results, "\n") ?: ''));
        if ($domain === '' || $results === '') return false;
        $parts = explode(';', $results);
        if ($authserv !== '' && trim(strtok($parts[0], " \t") ?: '') !== strtolower($authserv)) return false;
        foreach (array_slice($parts, 1) as $r) {
            $r = trim($r);
            if (preg_match('/^dmarc=pass\b.*header\.from=([^\s;]+)/', $r, $m) && self::sameDomain($m[1], $domain)) return true;
            if (preg_match('/^dkim=pass\b.*header\.(?:d|i)=@?([^\s;]+)/', $r, $m) && self::sameDomain($m[1], $domain)) return true;
        }
        return false;
    }

    private static function sameDomain(string $a, string $b): bool
    {
        $a = rtrim($a, '.'); $b = rtrim($b, '.');
        return $a === $b || str_ends_with($a, ".$b") || str_ends_with($b, ".$a");
    }
}
