<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The only way out for the headless browser: a small HTTP proxy on 127.0.0.1 that resolves each host itself,
 * refuses private and local addresses, and connects to the very address it checked. Chromium never resolves
 * names on its own, so a domain that answers a public address first and a private one next (DNS rebinding)
 * gets nowhere, and WebSockets (which DevTools request checks don't see) go through the same check.
 * Runs as its own process (talixio browser-proxy): prints its port, then ends when the parent closes stdin.
 */
final class BrowserProxy
{
    private const MAX_HEAD = 65536;

    /** @var array<int,array{c:resource,u:?resource,buf:string,target:string,left:int,tunnel:bool}> */
    private array $conns = [];

    public static function run(): void { (new self())->serve(); }

    private function serve(): void
    {
        $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $err) ?: throw new \RuntimeException("browser proxy: $err");
        echo parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT), "\n";
        fflush(STDOUT);
        while (true) {
            $read = [STDIN, $srv];
            foreach ($this->conns as $k) { $read[] = $k['c']; if ($k['u']) $read[] = $k['u']; }
            $w = $e = null;
            if (@stream_select($read, $w, $e, 60) === false) return;
            foreach ($read as $s) {
                if ($s === STDIN) { if (fread(STDIN, 8192) === '' && feof(STDIN)) return; continue; }
                if ($s === $srv) {
                    if ($c = @stream_socket_accept($srv, 0)) $this->conns[(int)$c] = ['c' => $c, 'u' => null, 'buf' => '', 'target' => '', 'left' => 0, 'tunnel' => false];
                    continue;
                }
                $this->pump($s);
            }
        }
    }

    private function pump($s): void
    {
        foreach ($this->conns as $id => $k) {
            if ($s === $k['u']) {
                $data = fread($s, 65536);
                if ($data === '' || $data === false) { $this->drop($id); return; }
                fwrite($k['c'], $data);
                return;
            }
            if ($s !== $k['c']) continue;
            $data = fread($s, 65536);
            if ($data === '' || $data === false) { $this->drop($id); return; }
            if ($k['tunnel']) { fwrite($k['u'], $data); return; }
            $this->conns[$id]['buf'] .= $data;
            $this->requests($id);
            return;
        }
    }

    /** Plain http: each request may name another host, so each one is checked and routed by itself. */
    private function requests(int $id): void
    {
        while (isset($this->conns[$id])) {
            $k = &$this->conns[$id];
            if ($k['left'] > 0) { // the rest of a request body
                $part = substr($k['buf'], 0, $k['left']);
                fwrite($k['u'], $part);
                $k['left'] -= strlen($part); $k['buf'] = (string)substr($k['buf'], strlen($part));
                if ($k['buf'] === '') return;
                continue;
            }
            $end = strpos($k['buf'], "\r\n\r\n");
            if ($end === false) { if (strlen($k['buf']) > self::MAX_HEAD) $this->refuse($id, 431, 'request head too large'); return; }
            $head = substr($k['buf'], 0, $end + 4); $k['buf'] = (string)substr($k['buf'], $end + 4);
            $lines = explode("\r\n", rtrim($head));
            if (!preg_match('#^([A-Z]+) (\S+) (HTTP/1\.[01])$#', array_shift($lines), $m)) { $this->refuse($id, 400, 'bad request'); return; }
            if ($m[1] === 'CONNECT') {
                if (!preg_match('#^(\[[0-9a-fA-F:.]+\]|[^:/\[\]]+):(\d{1,5})$#', $m[2], $t) || !$this->open($id, $t[1], (int)$t[2])) return;
                fwrite($k['c'], "HTTP/1.1 200 Connection established\r\n\r\n");
                $k['tunnel'] = true;
                if ($k['buf'] !== '') { fwrite($k['u'], $k['buf']); $k['buf'] = ''; }
                return;
            }
            $u = parse_url($m[2]);
            if (strtolower($u['scheme'] ?? '') !== 'http' || empty($u['host'])) { $this->refuse($id, 400, 'only http:// addresses go through this proxy'); return; }
            $len = 0; $out = [];
            foreach ($lines as $l) {
                $name = strtolower(strtok($l, ':'));
                if ($name === 'transfer-encoding') { $this->refuse($id, 501, 'chunked request bodies are not supported'); return; }
                if ($name === 'content-length') $len = max(0, (int)trim(substr($l, strlen($name) + 1)));
                if ($name !== 'proxy-connection' && $name !== 'proxy-authorization') $out[] = $l;
            }
            if (!$this->open($id, $u['host'], (int)($u['port'] ?? 80))) return;
            $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
            fwrite($k['u'], "{$m[1]} $path {$m[3]}\r\n" . implode("\r\n", $out) . "\r\n\r\n");
            $k['left'] = $len;
            if ($k['buf'] === '') return;
        }
    }

    /** Connects to the checked address of host:port, reusing the connection when the target is the same. */
    private function open(int $id, string $host, int $port): bool
    {
        $k = &$this->conns[$id];
        $target = strtolower("$host:$port");
        if ($k['u'] && $k['target'] === $target) return true;
        if ($k['u']) { fclose($k['u']); $k['u'] = null; }
        try { $ip = Http::publicIp($host); } catch (\Throwable $e) { $this->refuse($id, 403, $e->getMessage()); return false; }
        $u = @stream_socket_client('tcp://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . ":$port", $errno, $err, 10);
        if (!$u) { $this->refuse($id, 502, "cannot connect to $host: $err"); return false; }
        $k['u'] = $u; $k['target'] = $target;
        return true;
    }

    private function refuse(int $id, int $code, string $why): void
    {
        $body = "Blocked by Talixio: $why\n";
        @fwrite($this->conns[$id]['c'], "HTTP/1.1 $code Blocked\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
        $this->drop($id);
    }

    private function drop(int $id): void
    {
        $k = $this->conns[$id] ?? null;
        if (!$k) return;
        @fclose($k['c']);
        if ($k['u']) @fclose($k['u']);
        unset($this->conns[$id]);
    }
}
