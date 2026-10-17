<?php
declare(strict_types=1);
namespace Talixio;

/** Minimal IMAP client over TLS: fetch unseen messages from one folder and mark them seen. */
final class Imap
{
    /** @var resource */
    private $s;
    private int $tag = 0;

    /** @param string $security auto (993 = TLS, 143 = STARTTLS) | tls | starttls | none (local testing only)
     *  @param ?\Closure $token gives an OAuth access token: then AUTHENTICATE XOAUTH2 instead of LOGIN (Microsoft 365) */
    public function __construct(private string $host, private int $port, private string $user, private string $pass,
                                private string $folder = 'INBOX', private string $security = 'auto', private ?\Closure $token = null) {}

    /** @return array<int,string> uid => raw RFC 822 message */
    public function fetchUnseen(int $max = 10): array
    {
        $this->connect();
        try {
            $this->cmd('SELECT ' . self::quote($this->folder));
            $search = $this->cmd('UID SEARCH UNSEEN');
            preg_match('/^\* SEARCH([\d ]*)/m', $search, $m);
            $uids = array_slice(array_filter(array_map('intval', explode(' ', trim($m[1] ?? '')))), 0, $max);
            $out = [];
            foreach ($uids as $uid) {
                $resp = $this->cmd("UID FETCH $uid (BODY.PEEK[])");
                if (preg_match('/\{(\d+)\}\r\n/', $resp, $lm, PREG_OFFSET_CAPTURE)) {
                    $out[$uid] = substr($resp, $lm[0][1] + strlen($lm[0][0]), (int)$lm[1][0]);
                }
            }
            return $out;
        } finally {
            $this->close();
        }
    }

    public function markSeen(array $uids): void
    {
        if (!$uids) return;
        $this->connect();
        try {
            $this->cmd('SELECT ' . self::quote($this->folder));
            $this->cmd('UID STORE ' . implode(',', $uids) . ' +FLAGS.SILENT (\Seen)');
        } finally {
            $this->close();
        }
    }

    /** UIDs matching an IMAP SEARCH criteria string (newest last). */
    public function search(string $criteria, ?string $folder = null): array
    {
        $this->connect();
        try {
            $this->cmd('EXAMINE ' . self::quote($folder ?? $this->folder)); // read-only: never changes flags
            $resp = $this->cmd("UID SEARCH CHARSET UTF-8 $criteria");
            preg_match('/^\* SEARCH([\d ]*)/m', $resp, $m);
            return array_values(array_filter(array_map('intval', explode(' ', trim($m[1] ?? '')))));
        } finally {
            $this->close();
        }
    }

    /**
     * Messages by UID without marking them read. $maxBytes limits how much of each message is downloaded.
     * @return array<int,array{raw:string,flags:string}>
     */
    public function fetch(array $uids, int $maxBytes = 65536, ?string $folder = null): array
    {
        if (!$uids) return [];
        $this->connect();
        try {
            $this->cmd('EXAMINE ' . self::quote($folder ?? $this->folder));
            $out = [];
            foreach ($uids as $uid) {
                $resp = $this->cmd('UID FETCH ' . (int)$uid . " (FLAGS BODY.PEEK[]<0.$maxBytes>)");
                preg_match('/FLAGS \(([^)]*)\)/', $resp, $fm);
                if (preg_match('/\{(\d+)\}\r\n/', $resp, $lm, PREG_OFFSET_CAPTURE)) {
                    $out[(int)$uid] = ['raw' => substr($resp, $lm[0][1] + strlen($lm[0][0]), (int)$lm[1][0]), 'flags' => $fm[1] ?? ''];
                }
            }
            return $out;
        } finally {
            $this->close();
        }
    }

    /** Folders with their special-use flags (\Drafts, \Sent...). @return array<string,string> name => flags */
    public function folders(): array
    {
        $this->connect();
        try {
            $resp = $this->cmd('LIST "" "*"');
            $out = [];
            foreach (explode("\r\n", $resp) as $line) {
                if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))$/', $line, $m)) {
                    $out[stripcslashes($m[2] !== '' ? $m[2] : ($m[3] ?? ''))] = $m[1];
                }
            }
            return $out;
        } finally {
            $this->close();
        }
    }

    /** Store a message in a folder (drafts, sent copies). */
    public function append(string $folder, string $raw, string $flags = ''): void
    {
        $raw = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw);
        $this->connect();
        try {
            $tag = 'A' . (++$this->tag);
            fwrite($this->s, "$tag APPEND " . self::quote($folder) . ($flags !== '' ? " ($flags)" : '') . ' {' . strlen($raw) . "}\r\n");
            $line = (string)fgets($this->s, 4096);
            if (!str_starts_with($line, '+')) throw new \RuntimeException('IMAP APPEND refused: ' . trim($line));
            fwrite($this->s, $raw . "\r\n");
            while (($line = fgets($this->s, 65536)) !== false) {
                if (str_starts_with($line, "$tag ")) {
                    if (!str_starts_with($line, "$tag OK")) throw new \RuntimeException('IMAP APPEND failed: ' . trim(substr($line, strlen($tag) + 1)));
                    return;
                }
            }
            throw new \RuntimeException('IMAP connection closed');
        } finally {
            $this->close();
        }
    }

    private static function quote(string $s): string { return '"' . addcslashes($s, '"\\') . '"'; }

    private function connect(): void
    {
        $mode = $this->security === 'auto' ? ($this->port === 143 ? 'starttls' : 'tls') : $this->security;
        $ctx = stream_context_create(['ssl' => ['peer_name' => $this->host]]);
        $s = @stream_socket_client(($mode === 'tls' ? 'tls://' : 'tcp://') . "{$this->host}:{$this->port}", $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new \RuntimeException("IMAP connect to {$this->host}:{$this->port} failed: $err");
        stream_set_timeout($s, 30);
        $this->s = $s;
        $greeting = fgets($s, 4096);
        if (!str_starts_with((string)$greeting, '* OK')) throw new \RuntimeException('IMAP: unexpected greeting');
        if ($mode === 'starttls') {
            $this->cmd('STARTTLS');
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new \RuntimeException('IMAP STARTTLS failed');
        }
        if ($this->token) $this->cmd('AUTHENTICATE XOAUTH2 ' . Smtp::xoauth2($this->user, ($this->token)()), 'AUTHENTICATE');
        else $this->cmd('LOGIN ' . self::quote($this->user) . ' ' . self::quote($this->pass), 'LOGIN');
    }

    private function close(): void
    {
        try { $this->cmd('LOGOUT'); } catch (\Throwable) {}
        fclose($this->s);
    }

    /** Sends a command and reads until its tagged response, honouring {n} literals. */
    private function cmd(string $command, string $shown = ''): string
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, "$tag $command\r\n");
        $resp = '';
        while (($line = fgets($this->s, 65536)) !== false) {
            $resp .= $line;
            if ($shown === 'AUTHENTICATE' && str_starts_with($line, '+')) { fwrite($this->s, "\r\n"); continue; } // refused: an error in base64, then the tagged NO
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) { // literal: read exactly n bytes
                $need = (int)$m[1];
                while ($need > 0 && !feof($this->s)) { $chunk = fread($this->s, min($need, 65536)); $resp .= $chunk; $need -= strlen($chunk); }
                continue;
            }
            if (str_starts_with($line, "$tag ")) {
                if (!str_starts_with($line, "$tag OK")) throw new \RuntimeException('IMAP ' . ($shown ?: strtok($command, ' ')) . ' failed: ' . trim(substr($line, strlen($tag) + 1)));
                return $resp;
            }
        }
        throw new \RuntimeException('IMAP connection closed');
    }
}
