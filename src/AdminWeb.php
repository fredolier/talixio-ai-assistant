<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The admin page of a multi-user server (assistant.example.com/admin/). It never touches Docker or
 * the people's data itself: every action goes to the agent (Fleet), which only knows fixed actions.
 * Sign-in: password and an authenticator code, both required; optional list of allowed addresses;
 * every action is written to an audit log.
 */
final class AdminWeb
{
    private string $file;

    public function __construct(private string $dir, private string $agentUrl, private string $agentToken)
    {
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $this->file = "$dir/admin.json";
    }

    private function conf(): array { return json_decode((string)@file_get_contents($this->file), true) ?: []; }

    private function saveConf(array $c): void
    {
        file_put_contents("$this->file.tmp", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod("$this->file.tmp", 0600);
        rename("$this->file.tmp", $this->file);
    }

    /** At start: the one-time code that lets the first visitor set the admin password (shown in the container logs). */
    public function boot(): void
    {
        $c = $this->conf();
        if (!empty($c['password_hash'])) return;
        $c['setup_code'] ??= strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $this->saveConf($c);
        fwrite(STDERR, "\n  Talixio admin is not set up yet. Open /admin/ and use this setup code: {$c['setup_code']}\n\n");
    }

    public function audit(string $what, array $detail = []): void
    {
        $line = json_encode(['ts' => time(), 'ip' => Auth::clientIp(), 'what' => $what] + $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents("$this->dir/audit.log", $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function json(mixed $d, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function fail(int $code, string $msg): never { throw new AdminError($msg, $code); }

    // ---------- security ----------

    public static function ipAllowed(string $ip, array $list): bool
    {
        if (!$list) return true;
        foreach ($list as $cidr) {
            [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
            $a = @inet_pton($ip); $b = @inet_pton((string)$net);
            if ($a === false || $b === false || strlen($a) !== strlen($b)) continue;
            $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
            $bytes = intdiv($bits, 8); $rest = $bits % 8;
            if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) continue;
            if ($rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) return true;
        }
        return false;
    }

    private function throttled(string $ip): int
    {
        $f = "$this->dir/throttle.json"; $t = json_decode((string)@file_get_contents($f), true) ?: [];
        $recent = array_filter($t[$ip] ?? [], fn($ts) => $ts > time() - 900);
        return count($recent) >= 5 ? 900 - (time() - min($recent)) : 0;
    }

    private function failed(string $ip): void
    {
        $f = "$this->dir/throttle.json"; $t = json_decode((string)@file_get_contents($f), true) ?: [];
        foreach ($t as $k => $v) { $t[$k] = array_values(array_filter($v, fn($ts) => $ts > time() - 86400)); if (!$t[$k]) unset($t[$k]); }
        $t[$ip][] = time();
        file_put_contents($f, json_encode($t), LOCK_EX);
    }

    /** Authenticator code, each 30 s step usable once. */
    private function codeOk(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || $secret === '') return false;
        $c = $this->conf(); $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($step <= (int)($c['totp_last'] ?? 0)) continue;
            if (hash_equals(Auth::totp($secret, $step), $code)) { $c['totp_last'] = $step; $this->saveConf($c); return true; }
        }
        return false;
    }

    private function startSession(): void
    {
        $dir = "$this->dir/sessions"; if (!is_dir($dir)) mkdir($dir, 0700);
        session_save_path($dir);
        session_name('talixio_admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => Config::basePath() . '/', 'httponly' => true, 'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS'])]);
        ini_set('session.gc_maxlifetime', '43200');
        session_start();
    }

    // ---------- agent ----------

    private function agent(string $method, string $path, ?array $body = null, int $timeout = 300): array
    {
        $ch = curl_init(rtrim($this->agentUrl, '/') . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Agent-Token: ' . $this->agentToken]]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $out = curl_exec($ch);
        if ($out === false) { $e = curl_error($ch); curl_close($ch); $this->fail(502, "The agent is not reachable: $e"); }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $d = json_decode((string)$out, true) ?: [];
        if ($code >= 400) $this->fail($code === 400 ? 400 : 502, $d['error'] ?? "agent error $code");
        return $d;
    }

    // ---------- requests ----------

    public function handle(string $method, string $path): void
    {
        $c = $this->conf();
        if (!self::ipAllowed(Auth::clientIp(), $c['allow_ips'] ?? [])) { http_response_code(403); echo 'Not allowed from this address.'; return; }
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'");
        header('Referrer-Policy: same-origin');
        if (!str_starts_with($path, '/api/')) {
            $root = dirname(__DIR__) . '/web';
            $static = ['/admin.js' => ['admin/admin.js', 'text/javascript'], '/admin.css' => ['admin/admin.css', 'text/css'], '/qrcode.js' => ['public/vendor/qrcode.js', 'text/javascript'], '/icon.svg' => ['public/icon.svg', 'image/svg+xml'],
                '/i18n.js' => ['public/i18n.js', 'text/javascript'], '/i18n/fr.js' => ['public/i18n/fr.js', 'text/javascript']];
            if (isset($static[$path])) { header('Content-Type: ' . $static[$path][1] . '; charset=utf-8'); header('Cache-Control: no-cache'); readfile("$root/" . $static[$path][0]); return; }
            header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-cache');
            echo preg_replace('/<head>/', '<head><base href="' . htmlspecialchars(Config::basePath() . '/') . '">', (string)file_get_contents("$root/admin/admin.html"), 1);
            return;
        }
        $this->startSession();
        try {
            if ($method === 'POST' && ($_SERVER['HTTP_X_TALIXIO'] ?? '') !== '1') $this->fail(403, 'missing request header');
            $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $ip = Auth::clientIp();
            $configured = !empty($c['password_hash']);
            // ----- first visit: setup -----
            if ($path === '/api/me') { $this->json(['configured' => $configured, 'signed_in' => !empty($_SESSION['admin']) && ($_SESSION['gen'] ?? '') === ($c['gen'] ?? '')]); return; }
            if ($path === '/api/setup' && $method === 'POST') {
                if ($configured) $this->fail(400, 'Already set up.');
                if (($w = $this->throttled($ip)) > 0) $this->fail(429, 'Too many attempts. Try again in ' . ceil($w / 60) . ' min.');
                if (!hash_equals((string)($c['setup_code'] ?? ''), strtoupper(trim((string)($b['setup_code'] ?? ''))))) { $this->failed($ip); $this->fail(401, 'Wrong setup code (see: docker logs talixio-admin).'); }
                if (empty($b['password'])) { // step 1: the authenticator secret to scan
                    $_SESSION['pending_secret'] ??= Auth::newSecret();
                    $this->json(['secret' => $_SESSION['pending_secret'], 'uri' => 'otpauth://totp/' . rawurlencode('Talixio admin') . '?secret=' . $_SESSION['pending_secret'] . '&issuer=Talixio']);
                    return;
                }
                if (mb_strlen((string)$b['password']) < 12) $this->fail(400, 'The password needs at least 12 characters.');
                $secret = (string)($_SESSION['pending_secret'] ?? '');
                if (!$this->codeOk($secret, (string)($b['code'] ?? ''))) $this->fail(400, 'That code is not right: check the time on your phone and try the next one.');
                $c = $this->conf();
                $c = ['password_hash' => password_hash((string)$b['password'], PASSWORD_DEFAULT), 'totp_secret' => $secret, 'gen' => bin2hex(random_bytes(8)), 'allow_ips' => [], 'totp_last' => $c['totp_last'] ?? 0];
                $this->saveConf($c);
                unset($_SESSION['pending_secret']);
                session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['gen'] = $c['gen'];
                $this->audit('admin set up');
                $this->json(['ok' => true]);
                return;
            }
            if ($path === '/api/login' && $method === 'POST') {
                if (!$configured) $this->fail(400, 'Not set up yet.');
                if (($w = $this->throttled($ip)) > 0) $this->fail(429, 'Too many attempts. Try again in ' . ceil($w / 60) . ' min.');
                if (!password_verify((string)($b['password'] ?? ''), $c['password_hash']) || !$this->codeOk($c['totp_secret'], (string)($b['code'] ?? ''))) {
                    $this->failed($ip); $this->audit('sign-in failed'); $this->fail(401, 'Wrong password or code.');
                }
                session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['gen'] = $c['gen'];
                $this->audit('signed in');
                $this->json(['ok' => true]);
                return;
            }
            if (empty($_SESSION['admin']) || ($_SESSION['gen'] ?? '') !== ($c['gen'] ?? '')) $this->fail(401, 'Please sign in.');
            session_write_close();
            $this->route($method, $path, $b);
        } catch (AdminError $e) {
            $this->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('admin: ' . $e->getMessage());
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function route(string $m, string $path, array $b): void
    {
        $mm = [];
        if ($path === '/api/logout' && $m === 'POST') { session_start(); session_destroy(); $this->json(['ok' => true]); return; }
        if ($path === '/api/state') {
            $inst = $this->agent('GET', '/instances');
            $this->json($inst + ['server' => $this->agent('GET', '/server'), 'job' => $this->agent('GET', '/job'), 'allow_ips' => $this->conf()['allow_ips'] ?? [], 'my_ip' => Auth::clientIp(), 'version' => Version::get()]);
            return;
        }
        if ($path === '/api/check-update') { $this->json($this->agent('GET', '/check-update?' . http_build_query(['branch' => (string)($_GET['branch'] ?? 'main')]), null, 120)); return; }
        if ($path === '/api/job') { $this->json($this->agent('GET', '/job')); return; }
        if ($path === '/api/audit') {
            $lines = array_slice(file("$this->dir/audit.log", FILE_IGNORE_NEW_LINES) ?: [], -200);
            $this->json(['entries' => array_reverse(array_map(fn($l) => json_decode($l, true), $lines))]);
            return;
        }
        if ($path === '/api/instances' && $m === 'POST') {
            $r = $this->agent('POST', '/instances', array_intersect_key($b, array_flip(['id', 'name', 'email', 'memory_mb', 'cpus'])));
            $this->audit('instance created', ['id' => $r['id'] ?? '']);
            $this->json($r);
            return;
        }
        if (preg_match('#^/api/instances/([a-z0-9-]+)/(start|stop|restart|recreate|reset-password|reset-totp|doctor|logs|backup|limits|remove)$#', $path, $mm) && $m === 'POST') {
            [, $id, $act] = $mm;
            if ($act === 'remove' && ($b['confirm'] ?? '') !== $id) $this->fail(400, "Type the identifier ($id) to confirm.");
            $r = $this->agent('POST', "/instances/$id/$act", array_intersect_key($b, array_flip(['memory_mb', 'cpus', 'delete_data', 'all'])), 1000);
            $this->audit("instance $act", ['id' => $id] + ($act === 'remove' ? ['delete_data' => !empty($b['delete_data'])] : []) + ($act === 'limits' ? array_intersect_key($b, array_flip(['memory_mb', 'cpus'])) : []));
            $this->json($r);
            return;
        }
        if ($path === '/api/settings' && $m === 'POST') {
            $r = $this->agent('POST', '/settings', array_intersect_key($b, array_flip(['domain', 'mode', 'memory_mb', 'cpus', 'ollama', 'ollama_model', 'google_client_id', 'google_client_secret', 'apparmor_unconfined', 'eu_provider', 'eu_key', 'eu_url', 'eu_only'])), 1000);
            $this->audit('settings changed', ['keys' => array_keys($b)]); // names only, never the values
            $this->json($r);
            return;
        }
        if ($path === '/api/allow-ips' && $m === 'POST') {
            $list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($b['list'] ?? ''))), 'strlen'));
            foreach ($list as $x) if (@inet_pton(explode('/', $x)[0]) === false) $this->fail(400, "Not an address: $x");
            if ($list && !self::ipAllowed(Auth::clientIp(), $list)) $this->fail(400, 'Your current address (' . Auth::clientIp() . ') is not in the list: you would lock yourself out.');
            $c = $this->conf(); $c['allow_ips'] = $list; $this->saveConf($c);
            $this->audit('allowed addresses changed', ['list' => $list]);
            $this->json(['allow_ips' => $list]);
            return;
        }
        if ($path === '/api/password' && $m === 'POST') {
            $c = $this->conf();
            if (!password_verify((string)($b['current'] ?? ''), $c['password_hash']) || !$this->codeOk($c['totp_secret'], (string)($b['code'] ?? ''))) $this->fail(400, 'Wrong current password or code.');
            if (mb_strlen((string)($b['new'] ?? '')) < 12) $this->fail(400, 'The password needs at least 12 characters.');
            $c = $this->conf(); $c['password_hash'] = password_hash((string)$b['new'], PASSWORD_DEFAULT); $c['gen'] = bin2hex(random_bytes(8)); $this->saveConf($c);
            $this->audit('admin password changed');
            $this->json(['ok' => true]);
            return;
        }
        if ($path === '/api/transfer' && $m === 'POST') { // an assistant from another server (Transfer), new or over an existing one
            $this->agent('POST', '/transfer', ['id' => (string)($b['id'] ?? ''), 'name' => (string)($b['name'] ?? ''), 'source' => trim((string)($b['source'] ?? '')),
                'code' => trim((string)($b['code'] ?? '')), 'final' => !empty($b['final'])]);
            $this->audit('transfer in', ['id' => (string)($b['id'] ?? ''), 'source' => (string)($b['source'] ?? ''), 'final' => !empty($b['final'])]);
            $this->json(['ok' => true]);
            return;
        }
        foreach (['update', 'rollback', 'pull-model'] as $long) {
            if ($path === "/api/$long" && $m === 'POST') {
                $arg = match ($long) { 'update' => ['branch' => (string)($b['branch'] ?? 'main')], 'pull-model' => ['model' => (string)($b['model'] ?? '')], default => [] };
                $this->agent('POST', "/$long", $arg);
                $this->audit($long, $arg);
                $this->json(['ok' => true]);
                return;
            }
        }
        $this->fail(404, 'not found');
    }
}

final class AdminError extends \RuntimeException {}
