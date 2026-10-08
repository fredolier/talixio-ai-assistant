<?php
declare(strict_types=1);
namespace Talixio;

/** Minimal RFC 6455 WebSocket client (text frames), enough to talk to Chrome DevTools on localhost. */
final class WebSocket
{
    /** @var resource */
    private $s;

    public function __construct(string $url, int $timeout = 10)
    {
        $p = parse_url($url);
        $s = @stream_socket_client("tcp://{$p['host']}:{$p['port']}", $errno, $err, $timeout);
        if (!$s) throw new \RuntimeException("websocket connect failed: $err");
        $key = base64_encode(random_bytes(16));
        fwrite($s, "GET " . ($p['path'] ?? '/') . " HTTP/1.1\r\nHost: {$p['host']}:{$p['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $resp = '';
        while (($line = fgets($s)) !== false) { $resp .= $line; if ($line === "\r\n") break; }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!str_contains($resp, ' 101 ') || stripos($resp, $accept) === false) throw new \RuntimeException('websocket handshake failed');
        $this->s = $s;
    }

    public function send(string $text): void
    {
        $len = strlen($text);
        $head = chr(0x81) . match (true) { $len < 126 => chr(0x80 | $len), $len < 65536 => chr(0x80 | 126) . pack('n', $len), default => chr(0x80 | 127) . pack('J', $len) };
        $mask = random_bytes(4);
        $masked = $text ^ str_repeat($mask, intdiv($len, 4) + 1);
        $this->write($head . $mask . substr($masked, 0, $len));
    }

    /** Next complete text message, or null on timeout. */
    public function receive(float $timeout): ?string
    {
        $msg = '';
        while (true) {
            $r = [$this->s]; $w = $e = null;
            $sec = (int)$timeout; $usec = (int)(($timeout - $sec) * 1e6);
            if ($msg === '' && stream_get_meta_data($this->s)['unread_bytes'] === 0 && @stream_select($r, $w, $e, $sec, $usec) === 0) return null;
            $h = $this->read(2);
            $op = ord($h[0]) & 0x0f; $fin = (ord($h[0]) & 0x80) !== 0;
            $len = ord($h[1]) & 0x7f;
            if ($len === 126) $len = unpack('n', $this->read(2))[1];
            elseif ($len === 127) $len = unpack('J', $this->read(8))[1];
            $data = $len ? $this->read($len) : '';
            if ($op === 0x8) throw new \RuntimeException('websocket closed');
            if ($op === 0x9) { $this->write(chr(0x8a) . chr(0x80) . random_bytes(4)); continue; } // pong (empty, masked)
            if ($op === 0xA) continue;
            $msg .= $data;
            if ($fin) return $msg;
        }
    }

    private function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, $n - strlen($buf));
            if ($chunk === false || ($chunk === '' && feof($this->s))) throw new \RuntimeException('websocket connection lost');
            $buf .= $chunk;
        }
        return $buf;
    }

    private function write(string $data): void
    {
        for ($off = 0; $off < strlen($data);) {
            $n = fwrite($this->s, substr($data, $off));
            if (!$n) throw new \RuntimeException('websocket write failed');
            $off += $n;
        }
    }

    public function close(): void { @fclose($this->s); }
}
