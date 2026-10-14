<?php
declare(strict_types=1);
// Shared by tests/run.php and the files in tests/more/ (each of those also runs alone: php tests/more/<file>.php).
require_once __DIR__ . '/../src/autoload.php';

$GLOBALS['fails'] ??= 0;
function tap_tmp(string $content): string { $t = tempnam(sys_get_temp_dir(), 'tt'); file_put_contents($t, $content); return $t; }
function check(string $name, bool $ok, string $detail = ''): void {
    echo ($ok ? "ok   " : "FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    if (!$ok) $GLOBALS['fails']++;
}
/** Ends a test file run on its own: prints the summary and sets the exit code. */
function tests_done(): void
{
    echo $GLOBALS['fails'] ? "\n{$GLOBALS['fails']} FAILED\n" : "\nall passed\n";
    exit($GLOBALS['fails'] ? 1 : 0);
}
function free_port(): int // asks the OS for a free port instead of guessing
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    return $port;
}
function wait_port(int $port): void { for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); return; } usleep(50000); } }
/** A fresh temp data directory, removed when the process ends. */
function test_dir(string $name): string
{
    $d = sys_get_temp_dir() . "/talixio-$name-" . getmypid() . '-' . bin2hex(random_bytes(3));
    @mkdir($d, 0777, true);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($d)));
    return $d;
}
/**
 * Starts the web app on a free port with this environment; stopped when the process ends.
 * Returns a JSON client: $call(path, body|null, jar, method, headers) -> decoded body + _code (+ _raw, _headers).
 */
function web_app(string $dataDir, array $env = [], string $router = 'web/router.php'): \Closure
{
    $port = free_port();
    $root = dirname(__DIR__);
    // a coverage run swaps the router for one that records, and its ini must reach the server too
    $router = getenv('TALIXIO_TEST_ROUTER') ?: "$root/$router";
    if (getenv('PHP_INI_SCAN_DIR') !== false) $env += ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')];
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/web/public", $router],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dataDir/web.log", 'w'], 2 => ['file', "$dataDir/web.log", 'a']], $pp, null,
        $env + ['TALIXIO_DATA_DIR' => $dataDir, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'PATH' => getenv('PATH')]);
    register_shutdown_function(function () use ($p) { proc_terminate($p); proc_close($p); });
    wait_port($port);
    return function (string $path, ?array $body = null, string $jar = 'a', string $method = '', array $headers = []) use ($port, $dataDir) {
        $ch = curl_init("http://127.0.0.1:$port$path");
        $hdr = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => "$dataDir/ck-$jar", CURLOPT_COOKIEFILE => "$dataDir/ck-$jar", CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(['X-Talixio: 1', 'Content-Type: application/json', 'X-Forwarded-For: 203.0.113.7'], $headers),
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($h); }]
            + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)] : [])
            + ($method !== '' ? [CURLOPT_CUSTOMREQUEST => $method] : []));
        $raw = (string)curl_exec($ch);
        $out = json_decode($raw, true);
        $out = is_array($out) ? $out : [];
        $out['_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE); $out['_raw'] = $raw; $out['_headers'] = $hdr;
        curl_close($ch);
        return $out;
    };
}

/** A scriptable model: $fake->script = fn($last, $tools, $onUsage, $context, $messages, $onEvent) => 'reply'. */
final class FakeModel implements \Talixio\Provider {
    public array $calls = [];
    /** @var callable */ public $script;
    public function complete(string $system, string $context, array $messages, \Talixio\Tools $tools, callable $onUsage, ?callable $onEvent = null): string {
        $this->calls[] = compact('system', 'context', 'messages') + ['tools' => $tools->names()];
        return ($this->script)(\Talixio\Content::text(end($messages)["content"]), $tools, $onUsage, $context, $messages, $onEvent);
    }
}
