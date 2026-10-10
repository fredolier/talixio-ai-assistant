<?php
declare(strict_types=1);
namespace Talixio;

/**
 * "Sign in with Claude" from the web app: runs Claude Code's own `claude setup-token` (the official sign-in to a
 * Claude Pro/Max plan) in a terminal of its own, shows its sign-in link, passes back the code the person copies
 * from Claude's page, and keeps the token it prints. Talixio never sees the Claude password.
 * The terminal outlives the web request: a small background process (`talixio claude-login`) holds it, and the
 * web app talks to it through files in data/claude-login/.
 */
final class ClaudeLogin
{
    private const TIMEOUT = 900; // the sign-in link is valid this long

    public function __construct(private App $app) {}

    public static function installed(Config $c): bool
    {
        $bin = $c->claudeBin;
        if (str_contains($bin, '/')) return is_executable($bin);
        return trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) !== '';
    }

    private static function dir(string $dataDir): string { return "$dataDir/claude-login"; }

    private static function state(string $dir): array { return json_decode((string)@file_get_contents("$dir/state.json"), true) ?: ['state' => 'none']; }

    private static function setState(string $dir, array $s): void
    {
        file_put_contents("$dir/state.json.tmp", json_encode($s + ['ts' => time()], JSON_UNESCAPED_SLASHES));
        chmod("$dir/state.json.tmp", 0600);
        rename("$dir/state.json.tmp", "$dir/state.json");
    }

    /** Start a sign-in: returns the link to open (waits for Claude Code to print it). */
    public function start(): array
    {
        $c = $this->app->cfg;
        if (!self::installed($c)) throw new \InvalidArgumentException('Claude Code is not installed on this server (sudo ./install.sh --with-claude-code; it is in the Docker image).');
        $dir = self::dir($c->dataDir);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        @unlink("$dir/code");
        self::setState($dir, ['state' => 'starting']);
        @unlink("$dir/log");
        Background::start([PHP_BINARY, dirname(__DIR__) . '/bin/talixio', 'claude-login'], "$dir/log");
        for ($i = 0; $i < 60; $i++) {
            usleep(250_000);
            $s = self::state($dir);
            if ($s['state'] === 'waiting_code') return ['url' => $s['url']];
            if ($s['state'] === 'error') throw new \RuntimeException($s['message']);
        }
        throw new \RuntimeException('Claude Code did not show a sign-in link in time. Try again.');
    }

    /** The code from Claude's page: Claude Code exchanges it for a token; on success the assistant uses the plan. */
    public function finish(string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 400 || preg_match('/\s/', $code)) throw new \InvalidArgumentException('Paste the code shown on Claude\'s page after signing in.');
        $c = $this->app->cfg; $dir = self::dir($c->dataDir);
        if (self::state($dir)['state'] !== 'waiting_code') throw new \InvalidArgumentException('The sign-in has expired: start again.');
        file_put_contents("$dir/code", $code);
        chmod("$dir/code", 0600);
        for ($i = 0; $i < 240; $i++) {
            usleep(250_000);
            $s = self::state($dir);
            if ($s['state'] === 'done') {
                $token = (string)@file_get_contents("$dir/token");
                @unlink("$dir/token");
                self::setState($dir, ['state' => 'none']);
                $err = Settings::save($c->dataDir, ['CLAUDE_CODE_OAUTH_TOKEN' => $token, 'TALIXIO_PROVIDER' => 'claude-code']);
                if ($err) throw new \RuntimeException(implode(' ', $err));
                Log::info('claude sign-in: the assistant now uses the Claude plan');
                return ['ok' => true];
            }
            if ($s['state'] === 'error' || $s['state'] === 'waiting_code' && !empty($s['message'])) throw new \RuntimeException($s['message']);
        }
        throw new \RuntimeException('Claude did not answer in time. Try again.');
    }

    /** The background process: holds `claude setup-token` in a terminal until the code comes, then reads the token. */
    public static function run(Config $c): void
    {
        $dir = self::dir($c->dataDir);
        $env = ['HOME' => getenv('HOME') ?: $c->dataDir, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'TERM' => 'xterm', 'LANG' => 'C.UTF-8'];
        // a wide terminal: the link and the token stay on one line each
        $cmd = 'stty cols 4000 rows 50 2>/dev/null; exec ' . escapeshellarg($c->claudeBin) . ' setup-token';
        $p = proc_open(['script', '-qfec', $cmd, '/dev/null'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $c->dataDir, $env);
        if (!is_resource($p)) { self::setState($dir, ['state' => 'error', 'message' => 'could not start Claude Code']); return; }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $buf = ''; $mark = 0; $until = time() + self::TIMEOUT; $sent = false; $url = '';
        $plain = fn(string $s) => preg_replace('/\x1b\][^\x07]*\x07|\x1b\[[0-9;?<>=]*[A-Za-z~]|\x1b[78()=>][0-9A-B]?/', '', $s);
        try {
            while (time() < $until) {
                $r = [$pipes[1], $pipes[2]]; $w = $e = null;
                if (@stream_select($r, $w, $e, 0, 250_000)) foreach ($r as $h) $buf .= (string)fread($h, 65536);
                if ($url === '' && preg_match('#https://[a-z.]*claude\.(?:com|ai)/[^\s\x07\x1b"\']*oauth/authorize[^\s\x07\x1b"\']+#', $buf, $m)) {
                    $url = $m[0];
                    self::setState($dir, ['state' => 'waiting_code', 'url' => $url]);
                }
                if ($url !== '' && !$sent && is_file("$dir/code")) {
                    $code = trim((string)file_get_contents("$dir/code"));
                    @unlink("$dir/code");
                    $mark = strlen($buf);
                    fwrite($pipes[0], $code . "\r"); fflush($pipes[0]);
                    $sent = true;
                    self::setState($dir, ['state' => 'exchanging']);
                }
                $after = $plain(substr($buf, $mark));
                if ($sent && preg_match('/sk-ant-oat\d+-[A-Za-z0-9_\-]{20,}/', $after, $m)) {
                    file_put_contents("$dir/token", $m[0]); chmod("$dir/token", 0600);
                    self::setState($dir, ['state' => 'done']);
                    return;
                }
                if ($sent && preg_match('/(OAuth error[^\r\n]*|error:[^\r\n]*)/i', $after, $m)) { // e.g. a wrong or used code: a new code can be tried
                    $msg = trim(preg_replace('/\s*Press Enter to retry\.?/i', '', $m[1]));
                    self::setState($dir, ['state' => 'error', 'message' => "Claude refused the code ($msg). Start again and paste the whole code."]);
                    return;
                }
                if (!proc_get_status($p)['running']) {
                    if (self::state($dir)['state'] !== 'done') self::setState($dir, ['state' => 'error', 'message' => 'Claude Code stopped: ' . mb_substr(trim(preg_replace('/\s+/', ' ', $plain($buf))), -300)]);
                    return;
                }
            }
            self::setState($dir, ['state' => 'error', 'message' => 'The sign-in took too long: start again.']);
        } finally {
            @unlink("$dir/code");
            proc_terminate($p, 9); @proc_close($p);
        }
    }
}
