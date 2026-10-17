<?php
declare(strict_types=1);
namespace Talixio;

/** Minimal SMTP client: implicit TLS (465) or STARTTLS (587/25), AUTH PLAIN/LOGIN, or XOAUTH2 with a token (Microsoft 365). */
final class Smtp
{
    /** @var resource */
    private $s;

    /** @param ?\Closure $token gives an OAuth access token: then XOAUTH2 instead of the password */
    public function __construct(private string $host, private int $port, private string $security, private string $user, private string $pass, private ?\Closure $token = null) {}

    /**
     * @param array<string,string> $headers extra headers (In-Reply-To, References...)
     * @return string the Message-ID used
     */
    public function send(string $from, string $to, string $subject, string $markdown, array $headers = [], array $cc = [], ?string $html = null, array $parts = []): string
    {
        $domain = substr(strrchr(self::address($from), '@') ?: '@localhost', 1);
        $msgId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $this->connect();
        try {
            $this->cmd('MAIL FROM:<' . self::address($from) . '>', 250);
            foreach (array_merge([$to], $cc) as $rcpt) $this->cmd('RCPT TO:<' . self::address($rcpt) . '>', [250, 251]);
            $this->cmd('DATA', 354);
            $data = self::build($from, $to, $subject, $markdown, $msgId, $headers, $html, $parts);
            $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
            $this->write($data . "\r\n.");
            $this->expect(250);
            $this->cmd('QUIT', 221);
        } finally {
            fclose($this->s);
        }
        return $msgId;
    }

    /** Connect and log in without sending anything (for `talixio doctor`). */
    public function check(): void
    {
        $this->connect();
        try { $this->cmd('QUIT', 221); } finally { fclose($this->s); }
    }

    public static function address(string $mailbox): string
    {
        return preg_match('/<([^>]+)>/', $mailbox, $m) ? trim($m[1]) : trim($mailbox);
    }

    /**
     * $html: the HTML part (MailTemplate::page for the assistant's own mail); by default the Markdown, formatted.
     * $parts: more alternatives, [content type => body], e.g. an invitation ['text/calendar; method=REQUEST' => ics].
     */
    public static function build(string $from, string $to, string $subject, string $markdown, string $msgId, array $headers = [], ?string $html = null, array $parts = []): string
    {
        $b = 'b' . bin2hex(random_bytes(8));
        $html ??= MailTemplate::plain($markdown);
        $h = array_merge([
            'From' => self::encodeMailbox($from), 'To' => self::encodeMailbox($to),
            'Subject' => mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n"),
            'Date' => date('r'), 'Message-ID' => $msgId, 'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"$b\"", 'Auto-Submitted' => 'auto-generated',
        ], $headers);
        $h = array_filter($h, fn($v) => $v !== null && $v !== ''); // pass 'Auto-Submitted' => null for mail sent as the user
        $out = '';
        foreach ($h as $k => $v) $out .= "$k: $v\r\n";
        $part = fn(string $type, string $body) => "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
        $more = '';
        foreach ($parts as $type => $body) $more .= $part($type, $body);
        return $out . "\r\n" . $part('text/plain', $markdown) . $part('text/html', $html) . $more . "--$b--";
    }

    private static function encodeMailbox(string $m): string
    {
        if (!preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $m, $x) || $x[1] === '') return self::address($m);
        $name = trim($x[1], '"');
        return (preg_match('/[^\x20-\x7e]/', $name) ? mb_encode_mimeheader($name, 'UTF-8', 'Q') : '"' . addcslashes($name, '"\\') . '"') . " <{$x[2]}>";
    }

    private function connect(): void
    {
        $tls = $this->security === 'tls' || ($this->security === 'auto' && $this->port === 465);
        $ctx = stream_context_create(['ssl' => ['peer_name' => $this->host]]);
        $s = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . "{$this->host}:{$this->port}", $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new \RuntimeException("SMTP connect to {$this->host}:{$this->port} failed: $err");
        stream_set_timeout($s, 30);
        $this->s = $s;
        $this->expect(220);
        $ehlo = $this->cmd('EHLO ' . (gethostname() ?: 'talixio'), 250);
        if (!$tls && $this->security !== 'none') {
            if (!str_contains($ehlo, 'STARTTLS')) throw new \RuntimeException('SMTP server does not offer STARTTLS (set TALIXIO_SMTP_SECURITY=tls for port 465, or none only for a local relay)');
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new \RuntimeException('STARTTLS failed');
            $ehlo = $this->cmd('EHLO ' . (gethostname() ?: 'talixio'), 250);
        }
        if ($this->token) {
            $r = $this->cmd('AUTH XOAUTH2 ' . self::xoauth2($this->user, ($this->token)()), [235, 334]);
            if (str_starts_with($r, '334')) $this->cmd('', 235); // refused: the server explains in base64, then answers the empty line with the error
        } elseif ($this->user !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0{$this->user}\0{$this->pass}"), 235);
            } else {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($this->user), 334);
                $this->cmd(base64_encode($this->pass), 235);
            }
        }
    }

    /** The SASL XOAUTH2 initial response (RFC-less, as Google and Microsoft define it). */
    public static function xoauth2(string $user, string $token): string { return base64_encode("user=$user\x01auth=Bearer $token\x01\x01"); }

    private function write(string $line): void { fwrite($this->s, $line . "\r\n"); }

    private function cmd(string $line, int|array $ok): string
    {
        $this->write($line);
        return $this->expect($ok);
    }

    private function expect(int|array $ok): string
    {
        $resp = '';
        while (($line = fgets($this->s, 4096)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, (array)$ok, true)) {
            $shown = preg_replace('/^(AUTH \w+) .*/', '$1 ***', $resp);
            throw new \RuntimeException('SMTP error: ' . trim($shown ?: 'no response'));
        }
        return $resp;
    }
}
