<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Several people on one server: one Talixio container per person, run by this "agent", the only
 * part that talks to Docker. It only knows a closed list of actions (create, start, stop, remove an
 * instance, reset its password, update the image, reload Caddy) and builds every container from the
 * locked template below: whoever drives it (the admin page) can't pass Docker options of their own.
 *
 * Networks: each instance has its own network shared only with Caddy (instances can't reach each
 * other); Caddy, the admin page and this agent share "talixio-control"; Ollama is only reachable
 * through Caddy, which lets instances compute embeddings and nothing else.
 */
final class Fleet
{
    public const IMAGE = 'talixio';
    public const PREFIX = 'talixio-';          // containers, volumes and networks of instances: talixio-<id>
    public const CADDY = 'talixio-caddy';
    public const RESERVED = ['admin', 'api', 'cal', 'share', 'static', 'assets', 'www', 'mail', 'caddy', 'agent', 'ollama', 'control', 'current', 'previous'];

    private string $socket;
    private string $stateFile;

    public function __construct(private string $stateDir, ?string $socket = null)
    {
        $this->socket = $socket ?? (getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock');
        $this->stateFile = $stateDir . '/fleet.json';
        if (!is_dir($stateDir)) mkdir($stateDir, 0700, true);
    }

    // ---------- state ----------

    public function state(): array
    {
        $s = json_decode((string)@file_get_contents($this->stateFile), true) ?: [];
        $s['settings'] = ($s['settings'] ?? []) + ['domain' => getenv('TALIXIO_DOMAIN') ?: 'localhost', 'mode' => 'path', 'memory_mb' => 1024, 'cpus' => 1.5,
            'ollama' => true, 'ollama_model' => 'qwen3-embedding:0.6b', 'google_client_id' => '', 'google_client_secret' => '', 'apparmor_unconfined' => true];
        $s['instances'] = $s['instances'] ?? [];
        $s['image'] = $s['image'] ?? ['current' => self::IMAGE . ':current', 'previous' => null];
        return $s;
    }

    private function save(array $s): void
    {
        $tmp = $this->stateFile . '.tmp';
        file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod($tmp, 0600);
        rename($tmp, $this->stateFile);
    }

    /** Domain, path or subdomains, default limits, shared services. */
    public function setSettings(array $d): array
    {
        $s = $this->state(); $set = $s['settings'];
        if (isset($d['domain'])) {
            $dom = strtolower(trim((string)$d['domain']));
            if (!preg_match('/^(?=.{1,200}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$|^localhost$/', $dom)) throw new \InvalidArgumentException('Give a domain name like assistant.example.com.');
            $set['domain'] = $dom;
        }
        if (isset($d['mode'])) { if (!in_array($d['mode'], ['path', 'subdomain'], true)) throw new \InvalidArgumentException('mode: path or subdomain'); $set['mode'] = $d['mode']; }
        if (isset($d['memory_mb'])) $set['memory_mb'] = max(256, min(16384, (int)$d['memory_mb']));
        if (isset($d['cpus'])) $set['cpus'] = max(0.25, min(16.0, round((float)$d['cpus'], 2)));
        if (isset($d['ollama'])) $set['ollama'] = (bool)$d['ollama'];
        if (isset($d['ollama_model'])) { $m = trim((string)$d['ollama_model']); if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,80}$/i', $m)) throw new \InvalidArgumentException('Unexpected model name.'); $set['ollama_model'] = $m; }
        foreach (['google_client_id', 'google_client_secret'] as $k) if (isset($d[$k])) $set[$k] = mb_substr(trim((string)$d[$k]), 0, 300);
        if (isset($d['apparmor_unconfined'])) $set['apparmor_unconfined'] = (bool)$d['apparmor_unconfined'];
        $s['settings'] = $set;
        $this->save($s);
        return $set;
    }

    public static function validId(string $id): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9-]{1,29}$/', $id) && !str_ends_with($id, '-') && !in_array($id, self::RESERVED, true);
    }

    private function need(string $id): array
    {
        $i = $this->state()['instances'][$id] ?? null;
        if (!$i) throw new \InvalidArgumentException("no instance $id");
        return $i;
    }

    /** Where the person opens their assistant. */
    public function url(string $id, ?array $set = null): string
    {
        $set ??= $this->state()['settings'];
        return $set['mode'] === 'subdomain' ? "https://$id.{$set['domain']}/" : "https://{$set['domain']}/$id/";
    }

    // ---------- the locked template ----------

    /** The only container shape this agent ever creates. */
    public function spec(string $id, array $s, ?string $image = null): array
    {
        $i = $s['instances'][$id]; $set = $s['settings'];
        $env = ['TALIXIO_BASE_PATH=' . ($set['mode'] === 'path' ? $id : ''), 'TALIXIO_INSTANCE=' . $id, 'TZ=' . ($i['timezone'] ?? 'Europe/Paris'), 'TALIXIO_TIMEZONE=' . ($i['timezone'] ?? 'Europe/Paris')];
        if (!empty($i['password_hash'])) $env[] = 'TALIXIO_INIT_PASSWORD_HASH=' . $i['password_hash']; // written once into the volume, then the person's own
        if (!empty($i['reset'])) $env[] = 'TALIXIO_RESET=' . $i['reset'];                                // a new password or 2-step reset, applied once
        if ($set['ollama']) array_push($env, 'TALIXIO_EMBEDDINGS=ollama', 'TALIXIO_EMBEDDINGS_MODEL=' . $set['ollama_model'], 'TALIXIO_OLLAMA_URL=http://' . self::CADDY . ':11434');
        if ($set['google_client_id'] !== '') array_push($env, 'TALIXIO_GOOGLE_CLIENT_ID=' . $set['google_client_id'], 'TALIXIO_GOOGLE_CLIENT_SECRET=' . $set['google_client_secret']);
        $secOpt = ['no-new-privileges', 'seccomp=' . (string)file_get_contents(dirname(__DIR__) . '/deploy/docker/seccomp-sandbox.json')];
        if ($set['apparmor_unconfined']) $secOpt[] = 'apparmor=unconfined'; // Ubuntu: Docker's profile forbids the sandboxes' mounts (seccomp and no privileges still apply)
        return [
            'Image' => $image ?? $s['image']['current'],
            'Hostname' => $id,
            'Env' => $env,
            'Labels' => ['talixio.instance' => $id, 'talixio.managed' => '1'],
            'Healthcheck' => ['Test' => ['CMD', 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:8080/sw.js'], 'Interval' => 30_000_000_000, 'Timeout' => 5_000_000_000, 'StartPeriod' => 20_000_000_000],
            'HostConfig' => [
                'Mounts' => [['Type' => 'volume', 'Source' => self::PREFIX . $id, 'Target' => '/data']],
                'Memory' => (int)$i['memory_mb'] * 1048576,
                'MemoryReservation' => (int)min(512, (int)$i['memory_mb'] / 2) * 1048576,
                'NanoCpus' => (int)round((float)$i['cpus'] * 1e9),
                'PidsLimit' => 512,
                'ReadonlyRootfs' => true,
                'Tmpfs' => ['/tmp' => 'rw,nosuid,nodev,size=512m'],
                'CapDrop' => ['ALL'],
                'SecurityOpt' => $secOpt,
                'MaskedPaths' => [], 'ReadonlyPaths' => [], // = systempaths=unconfined: bubblewrap mounts its own /proc
                'Privileged' => false,
                'RestartPolicy' => ['Name' => 'unless-stopped'],
                'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '10m', 'max-file' => '3']],
                'NetworkMode' => self::PREFIX . 'i-' . $id,
            ],
        ];
    }

    // ---------- Docker Engine API (unix socket) ----------

    /** @return array{0:int,1:mixed} status, decoded body (or raw string) */
    public function docker(string $method, string $path, ?array $body = null, array $query = [], int $timeout = 60, ?string $raw = null, array $headers = []): array
    {
        $ch = curl_init('http://docker/v1.44' . $path . ($query ? '?' . http_build_query($query) : ''));
        curl_setopt_array($ch, [CURLOPT_UNIX_SOCKET_PATH => $this->socket, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        elseif ($raw !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        $out = curl_exec($ch);
        if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new \RuntimeException("Docker is not reachable: $e"); }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $d = json_decode((string)$out, true);
        return [$code, $d ?? $out];
    }

    private function ok(array $r, string $what, array $okCodes = [200, 201, 204, 304]): mixed
    {
        [$code, $d] = $r;
        if (!in_array($code, $okCodes, true)) throw new \RuntimeException("$what: " . (is_array($d) ? ($d['message'] ?? json_encode($d)) : mb_substr((string)$d, 0, 300)) . " (HTTP $code)");
        return $d;
    }

    /** Run a command in a container; returns [exit code, output]. Only used with the fixed commands below. */
    public function exec(string $container, array $cmd, int $timeout = 300): array
    {
        $e = $this->ok($this->docker('POST', "/containers/$container/exec", ['Cmd' => $cmd, 'AttachStdout' => true, 'AttachStderr' => true]), 'exec');
        [, $raw] = $this->docker('POST', "/exec/{$e['Id']}/start", ['Detach' => false, 'Tty' => false], [], $timeout);
        $raw = is_string($raw) ? $raw : json_encode($raw);
        $out = ''; // multiplexed stream: 8-byte headers
        for ($p = 0; $p + 8 <= strlen($raw);) { $len = unpack('N', substr($raw, $p + 4, 4))[1]; $out .= substr($raw, $p + 8, $len); $p += 8 + $len; }
        $info = $this->ok($this->docker('GET', "/exec/{$e['Id']}/json"), 'exec');
        return [(int)($info['ExitCode'] ?? -1), $out];
    }

    private function containerName(string $id): string { return self::PREFIX . $id; }

    private function ensureNetwork(string $name): void
    {
        [$code] = $this->docker('GET', "/networks/$name");
        if ($code === 404) $this->ok($this->docker('POST', '/networks/create', ['Name' => $name, 'Driver' => 'bridge', 'Labels' => ['talixio.managed' => '1']]), "network $name");
    }

    private function connect(string $network, string $container): void
    {
        [$code, $d] = $this->docker('POST', "/networks/$network/connect", ['Container' => $container]);
        if ($code >= 400 && !str_contains(is_array($d) ? ($d['message'] ?? '') : (string)$d, 'already exists')) throw new \RuntimeException("connect $container to $network: " . (is_array($d) ? ($d['message'] ?? '') : $d));
    }

    private function createAndStart(string $id, array $s, ?string $image = null): void
    {
        $name = $this->containerName($id);
        $this->ensureNetwork(self::PREFIX . 'i-' . $id);
        [$code] = $this->docker('GET', '/volumes/' . self::PREFIX . $id);
        if ($code === 404) $this->ok($this->docker('POST', '/volumes/create', ['Name' => self::PREFIX . $id, 'Labels' => ['talixio.instance' => $id]]), 'volume');
        $this->ok($this->docker('POST', '/containers/create', $this->spec($id, $s, $image), ['name' => $name]), "create $name");
        $this->ok($this->docker('POST', "/containers/$name/start"), "start $name");
        $this->connect(self::PREFIX . 'i-' . $id, self::CADDY); // Caddy is the only other member of this network
    }

    private function removeContainer(string $id): void
    {
        $name = $this->containerName($id);
        $this->docker('POST', "/containers/$name/stop", null, ['t' => 15], 30);
        [$code, $d] = $this->docker('DELETE', "/containers/$name", null, ['force' => 'true']);
        if ($code >= 400 && $code !== 404) throw new \RuntimeException("remove $name: " . (is_array($d) ? ($d['message'] ?? '') : $d));
    }

    // ---------- actions ----------

    /** A new person. Returns the first password (shown once). */
    public function create(string $id, string $name = '', string $email = '', ?int $memoryMb = null, ?float $cpus = null): array
    {
        if (!self::validId($id)) throw new \InvalidArgumentException('The identifier is 2 to 30 lower-case letters, digits or dashes, starting with a letter (not ' . implode(', ', array_slice(self::RESERVED, 0, 6)) . '…).');
        $s = $this->state();
        if (isset($s['instances'][$id])) throw new \InvalidArgumentException("\"$id\" already exists.");
        $password = self::password();
        $s['instances'][$id] = ['name' => mb_substr(trim($name), 0, 80), 'email' => mb_substr(trim($email), 0, 200), 'memory_mb' => $memoryMb ?? $s['settings']['memory_mb'],
            'cpus' => $cpus ?? $s['settings']['cpus'], 'created' => time(), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'reset' => '', 'timezone' => 'Europe/Paris'];
        $this->save($s);
        try { $this->createAndStart($id, $s); }
        catch (\Throwable $e) { $s = $this->state(); unset($s['instances'][$id]); $this->save($s); try { $this->removeContainer($id); } catch (\Throwable) {} throw $e; }
        $this->reloadCaddy();
        return ['id' => $id, 'password' => $password, 'url' => $this->url($id)];
    }

    /** An existing install moved into an instance (its volume already holds the data). */
    public function adopt(string $id, string $name = ''): array
    {
        if (!self::validId($id)) throw new \InvalidArgumentException('invalid identifier');
        $s = $this->state();
        $s['instances'][$id] = ($s['instances'][$id] ?? []) + ['name' => $name, 'email' => '', 'memory_mb' => $s['settings']['memory_mb'], 'cpus' => $s['settings']['cpus'],
            'created' => time(), 'password_hash' => '', 'reset' => '', 'timezone' => 'Europe/Paris'];
        $this->save($s);
        $this->removeContainer($id);
        $this->createAndStart($id, $s);
        $this->reloadCaddy();
        return ['id' => $id, 'url' => $this->url($id)];
    }

    public function start(string $id): void { $this->need($id); $this->ok($this->docker('POST', '/containers/' . $this->containerName($id) . '/start'), 'start'); }
    public function stop(string $id): void { $this->need($id); $this->ok($this->docker('POST', '/containers/' . $this->containerName($id) . '/stop', null, ['t' => 15], 30), 'stop'); }
    public function restart(string $id): void { $this->need($id); $this->ok($this->docker('POST', '/containers/' . $this->containerName($id) . '/restart', null, ['t' => 15], 40), 'restart'); }

    /** Same data, container made again (new image, new limits, new settings). */
    public function recreate(string $id, ?string $image = null): void
    {
        $this->need($id);
        $this->removeContainer($id);
        $this->createAndStart($id, $this->state(), $image);
    }

    public function setLimits(string $id, int $memoryMb, float $cpus): void
    {
        $s = $this->state(); $this->need($id);
        $s['instances'][$id]['memory_mb'] = max(256, min(16384, $memoryMb));
        $s['instances'][$id]['cpus'] = max(0.25, min(16.0, round($cpus, 2)));
        $this->save($s);
        $this->recreate($id);
    }

    /** Forgotten password: a new one (shown once); every device signs in again. */
    public function resetPassword(string $id): string
    {
        $s = $this->state(); $this->need($id);
        $password = self::password();
        $s['instances'][$id]['reset'] = bin2hex(random_bytes(6)) . ':password:' . password_hash($password, PASSWORD_DEFAULT);
        $this->save($s);
        $this->recreate($id);
        return $password;
    }

    /** Lost phone: two-step sign-in off (the person turns it on again). */
    public function resetTotp(string $id): void
    {
        $s = $this->state(); $this->need($id);
        $s['instances'][$id]['reset'] = bin2hex(random_bytes(6)) . ':totp';
        $this->save($s);
        $this->recreate($id);
    }

    /** $deleteData: also the volume (the person's database, library, memory) — not reversible. */
    public function remove(string $id, bool $deleteData = false): void
    {
        $this->need($id);
        $this->removeContainer($id);
        $net = self::PREFIX . 'i-' . $id;
        $this->docker('POST', "/networks/$net/disconnect", ['Container' => self::CADDY, 'Force' => true]);
        $this->docker('DELETE', "/networks/$net");
        if ($deleteData) $this->docker('DELETE', '/volumes/' . self::PREFIX . $id);
        $s = $this->state(); unset($s['instances'][$id]); $this->save($s);
        $this->reloadCaddy();
    }

    public function doctor(string $id): string { $this->need($id); return $this->exec($this->containerName($id), ['php', '/opt/talixio/bin/talixio', 'doctor'], 120)[1]; }
    public function backup(string $id): string { $this->need($id); [$code, $out] = $this->exec($this->containerName($id), ['php', '/opt/talixio/bin/talixio', 'backup'], 900); if ($code) throw new \RuntimeException(trim($out)); return trim($out); }

    /** Every instance with its container state, memory and last activity (the last line it logged). */
    public function list(): array
    {
        $s = $this->state(); $out = [];
        foreach ($s['instances'] as $id => $i) {
            $row = ['id' => $id, 'name' => $i['name'], 'email' => $i['email'], 'memory_mb' => $i['memory_mb'], 'cpus' => $i['cpus'], 'created' => $i['created'], 'url' => $this->url($id, $s['settings']),
                'state' => 'missing', 'health' => '', 'image' => '', 'mem_used_mb' => null, 'last_activity' => null, 'version' => null];
            [$code, $c] = $this->docker('GET', '/containers/' . $this->containerName($id) . '/json');
            if ($code === 200) {
                $row['state'] = $c['State']['Status'] ?? '?';
                $row['health'] = $c['State']['Health']['Status'] ?? '';
                $row['image'] = $c['Config']['Image'] ?? '';
                $row['started'] = $c['State']['StartedAt'] ?? null;
                if ($row['state'] === 'running') {
                    [, $st] = $this->docker('GET', '/containers/' . $this->containerName($id) . '/stats', null, ['stream' => 'false', 'one-shot' => 'true'], 10);
                    if (is_array($st) && isset($st['memory_stats']['usage'])) $row['mem_used_mb'] = (int)round(($st['memory_stats']['usage'] - ($st['memory_stats']['stats']['inactive_file'] ?? 0)) / 1048576);
                    $row['last_activity'] = $this->lastVisit($id);
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** When the person last used their assistant: the last request that came through Caddy (not the health checks). */
    private function lastVisit(string $id): ?int
    {
        [, $log] = $this->docker('GET', '/containers/' . $this->containerName($id) . '/logs', null, ['stdout' => 'true', 'stderr' => 'true', 'tail' => '3000', 'timestamps' => 'true'], 10); // health checks fill the log: about 12 hours
        if (!is_string($log)) return null;
        $last = null;
        foreach (explode("\n", $log) as $line) {
            if (preg_match('/(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)\S*\s.*\]\s(\S+):\d+ Accepted/', $line, $m) && !str_starts_with($m[2], '127.')) $last = strtotime($m[1] . 'Z');
        }
        return $last;
    }

    /** Server memory and disk, for the overview. */
    public function server(): array
    {
        $mem = [];
        foreach (@file('/proc/meminfo') ?: [] as $l) if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)round($m[2] / 1024);
        [, $df] = $this->docker('GET', '/system/df', null, ['type' => 'volume'], 60);
        $vol = [];
        foreach ((is_array($df) ? ($df['Volumes'] ?? []) : []) as $v) if (str_starts_with($v['Name'], self::PREFIX)) $vol[substr($v['Name'], strlen(self::PREFIX))] = (int)round(($v['UsageData']['Size'] ?? 0) / 1048576);
        $disk = @disk_total_space('/') ? ['total_gb' => round(disk_total_space('/') / 1e9, 1), 'free_gb' => round(disk_free_space('/') / 1e9, 1)] : null;
        return ['memory_mb' => $mem, 'volumes_mb' => $vol, 'disk' => $disk, 'image' => $this->state()['image']];
    }

    // ---------- Caddy ----------

    /** The whole Caddy configuration, from the instances and settings. */
    public function caddyfile(?array $s = null): string
    {
        $s ??= $this->state(); $set = $s['settings']; $dom = $set['domain'];
        $ids = array_keys($s['instances']); sort($ids);
        $common = "\tencode zstd gzip\n\theader {\n\t\t-Server\n\t\tStrict-Transport-Security \"max-age=31536000\"\n\t\tX-Content-Type-Options nosniff\n\t\tReferrer-Policy same-origin\n\t}\n";
        $admin = "\tredir /admin /admin/\n\thandle_path /admin/* {\n\t\treverse_proxy talixio-admin:8080\n\t}\n";
        $home = "\thandle {\n\t\theader Content-Type \"text/html; charset=utf-8\"\n\t\trespond \"<!doctype html><meta name=viewport content='width=device-width'><title>Talixio</title><p style='font:16px system-ui;margin:3em auto;max-width:30em'>Talixio. Open the address you were given.</p>\" 404\n\t}\n";
        $out = "# managed by the Talixio agent: changes here are overwritten\n{\n\temail " . (getenv('ACME_EMAIL') ?: 'admin@' . $dom) . "\n"
            . ($set['mode'] === 'subdomain' ? "\ton_demand_tls {\n\t\task http://talixio-agent:8090/ask\n\t}\n" : '') . "}\n\n";
        $site = ($dom === 'localhost' ? 'http://localhost' : $dom) . " {\n$common$admin";
        if ($set['mode'] === 'path') {
            foreach ($ids as $id) $site .= "\tredir /$id /$id/\n\thandle_path /$id/* {\n\t\treverse_proxy {$this->containerName($id)}:8080\n\t}\n";
        }
        $out .= $site . $home . "}\n\n";
        if ($set['mode'] === 'subdomain' && $dom !== 'localhost') {
            $out .= "*.$dom {\n\ttls {\n\t\ton_demand\n\t}\n$common";
            foreach ($ids as $id) $out .= "\t@$id host $id.$dom\n\thandle @$id {\n\t\treverse_proxy {$this->containerName($id)}:8080\n\t}\n";
            $out .= $home . "}\n\n";
        }
        // Ollama for the instances: embeddings only (it has no password: nothing else gets through)
        $out .= "http://:11434 {\n\t@embed path /api/embed /api/embeddings /api/tags /api/version\n\thandle @embed {\n\t\treverse_proxy talixio-ollama:11434\n\t}\n\trespond 403\n}\n";
        return $out;
    }

    /** May Caddy get a certificate for this host? (subdomain mode: only for existing instances) */
    public function ask(string $host): bool
    {
        $s = $this->state(); $dom = $s['settings']['domain'];
        if ($s['settings']['mode'] !== 'subdomain' || !str_ends_with($host, ".$dom")) return false;
        return isset($s['instances'][substr($host, 0, -strlen(".$dom"))]);
    }

    public function reloadCaddy(): void
    {
        $file = getenv('CADDY_CONFIG_DIR') ?: '/caddy-config';
        if (!is_dir($file)) return; // no Caddy managed here (tests)
        file_put_contents("$file/Caddyfile.new", $this->caddyfile());
        rename("$file/Caddyfile.new", "$file/Caddyfile");
        [$code, $out] = $this->exec(self::CADDY, ['caddy', 'reload', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], 60);
        if ($code !== 0) throw new \RuntimeException('Caddy refused the configuration: ' . trim($out));
    }

    // ---------- updates ----------

    /** Build a new image from the git repository (TALIXIO_GIT_URL, a branch), then move every instance to it, one by one. */
    public function update(string $branch, callable $progress): void
    {
        $url = getenv('TALIXIO_GIT_URL') ?: '';
        if ($url === '') throw new \RuntimeException('TALIXIO_GIT_URL is not set (in the stack\'s .env).');
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) throw new \InvalidArgumentException('bad branch name');
        $tag = self::IMAGE . ':' . date('Ymd-His');
        $progress('building', "Building $tag from $branch…");
        $this->build("$url#$branch", $tag, $progress);
        $s = $this->state();
        $old = $s['image']['current'];
        [, $oldImg] = $this->docker('GET', '/images/' . rawurlencode($old) . '/json');
        $this->tag($tag, self::IMAGE, 'current');
        if (is_array($oldImg) && isset($oldImg['Id'])) $this->tag($oldImg['Id'], self::IMAGE, 'previous');
        $s['image'] = ['current' => self::IMAGE . ':current', 'previous' => is_array($oldImg) ? self::IMAGE . ':previous' : null, 'built' => $tag, 'built_at' => time(), 'branch' => $branch];
        $this->save($s);
        foreach (array_keys($s['instances']) as $id) {
            $progress('rolling', "Moving $id to the new version…");
            $this->recreate($id);
            if (!$this->waitHealthy($id, 90)) {
                $progress('failed', "$id did not start correctly on the new version: it is back on the previous one, and the update stopped.");
                if ($s['image']['previous']) { $this->tag(self::IMAGE . ':previous', self::IMAGE, 'current'); $this->recreate($id); }
                throw new \RuntimeException("$id did not start on the new version (back on the previous one).");
            }
        }
        $progress('done', 'Every instance runs the new version.');
    }

    /** Back to the image before the last update. */
    public function rollback(callable $progress): void
    {
        [$code, $img] = $this->docker('GET', '/images/' . rawurlencode(self::IMAGE . ':previous') . '/json');
        if ($code !== 200) throw new \RuntimeException('No previous version to go back to.');
        [, $cur] = $this->docker('GET', '/images/' . rawurlencode(self::IMAGE . ':current') . '/json');
        $this->tag($img['Id'], self::IMAGE, 'current');
        if (is_array($cur) && isset($cur['Id'])) $this->tag($cur['Id'], self::IMAGE, 'previous');
        foreach (array_keys($this->state()['instances']) as $id) { $progress('rolling', "Moving $id back…"); $this->recreate($id); }
        $progress('done', 'Back on the previous version.');
    }

    private function tag(string $image, string $repo, string $tag): void
    {
        $this->ok($this->docker('POST', '/images/' . rawurlencode($image) . '/tag', null, ['repo' => $repo, 'tag' => $tag]), 'tag', [200, 201]);
    }

    private function build(string $remote, string $tag, callable $progress): void
    {
        $ch = curl_init('http://docker/v1.44/build?' . http_build_query(['remote' => $remote, 't' => $tag, 'rm' => '1', 'forcerm' => '1', 'pull' => '1']));
        $buf = ''; $err = null;
        curl_setopt_array($ch, [CURLOPT_UNIX_SOCKET_PATH => $this->socket, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_TIMEOUT => 3600,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-tar'],
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, &$err, $progress) {
                $buf .= $chunk;
                while (($p = strpos($buf, "\n")) !== false) {
                    $line = json_decode(substr($buf, 0, $p), true); $buf = substr($buf, $p + 1);
                    if (!is_array($line)) continue;
                    if (isset($line['error'])) $err = $line['error'];
                    elseif (isset($line['stream']) && preg_match('/^Step \d+\/\d+/', $line['stream'])) $progress('building', trim($line['stream']));
                }
                return strlen($chunk);
            }]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($err || $code >= 400) throw new \RuntimeException('The build failed: ' . ($err ?? "HTTP $code"));
    }

    private function waitHealthy(string $id, int $seconds): bool
    {
        for ($t = 0; $t < $seconds; $t += 3) {
            [, $c] = $this->docker('GET', '/containers/' . $this->containerName($id) . '/json');
            $h = $c['State']['Health']['Status'] ?? '';
            if ($h === 'healthy') return true;
            if (($c['State']['Status'] ?? '') === 'exited') return false;
            sleep(3);
        }
        return false;
    }

    /** Ollama: the model the instances use for embeddings (downloaded once, shared). */
    public function pullModel(): string
    {
        $m = $this->state()['settings']['ollama_model'];
        [$code, $out] = $this->exec('talixio-ollama', ['ollama', 'pull', $m], 1800);
        if ($code !== 0) throw new \RuntimeException("ollama pull $m: " . trim(mb_substr($out, -300)));
        return "$m ready";
    }

    public static function password(): string { return substr(strtr(base64_encode(random_bytes(18)), '+/=', 'xyz'), 0, 20); }
}
