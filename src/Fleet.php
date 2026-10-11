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
    public const BASE_IMAGE = 'talixio-base'; // the dependencies (deploy/docker/base.Dockerfile), tagged with that file's SHA-1
    public const WHISPER = 'talixio-whisper';  // whisper.cpp models, shared read-only by everyone (install-stack.sh fills it)
    public const PREFIX = 'talixio-';          // containers, volumes and networks of instances: talixio-<id>
    public const CADDY = 'talixio-caddy';
    public const RESERVED = ['admin', 'api', 'cal', 'share', 'static', 'assets', 'www', 'mail', 'caddy', 'agent', 'ollama', 'control', 'current', 'previous', 'whisper'];

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

    /**
     * The long action the admin page follows (job.json). One whose process is gone (the agent was restarted or
     * replaced, e.g. by install-stack.sh, while it ran) is marked as interrupted, so it no longer blocks the page.
     */
    public function job(): array
    {
        $file = "$this->stateDir/job.json";
        $job = json_decode((string)@file_get_contents($file), true) ?: ['state' => 'none'];
        if (($job['state'] ?? '') !== 'running') return $job;
        $pid = (int)($job['pid'] ?? 0);
        if ($pid > 0 && ($job['host'] ?? '') === gethostname() && str_contains((string)@file_get_contents("/proc/$pid/cmdline"), 'fleet-job.php')) return $job;
        if ($pid === 0 && time() - (int)($job['updated'] ?? 0) < 120) return $job; // just started: its process has not written yet
        $msg = 'Interrupted: the process running it stopped (the agent restarted, or an update was made from the command line). Check the version in service, and start it again if needed.';
        $job = ['state' => 'failed', 'phase' => 'interrupted', 'message' => $msg, 'log' => array_merge(array_slice((array)($job['log'] ?? []), -39), [date('H:i:s') . ' ' . $msg]), 'updated' => time()] + $job;
        @file_put_contents("$file.tmp", json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @rename("$file.tmp", $file);
        return $job;
    }

    /** After install-stack.sh built talixio:current itself: what runs is recorded, as an update from the admin page would. */
    public function installed(string $branch): void
    {
        $tag = date('Ymd-His');
        $this->tag(self::IMAGE . ':current', self::IMAGE, $tag);
        $s = $this->state();
        $s['image'] = ['current' => self::IMAGE . ':current', 'previous' => $s['image']['previous'] ?? null, 'built' => self::IMAGE . ":$tag", 'built_at' => time(),
            'branch' => preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch) ? $branch : ($s['image']['branch'] ?? '')];
        $this->save($s);
        $this->job(); // a job the restart cut short
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
                'Mounts' => [['Type' => 'volume', 'Source' => self::PREFIX . $id, 'Target' => '/data'],
                    ['Type' => 'volume', 'Source' => self::WHISPER, 'Target' => '/opt/whisper.cpp/models', 'ReadOnly' => true]], // shared transcription models
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

    /**
     * Bring an assistant from another server (Transfer): a new person, or a fresh copy over an existing one.
     * Its container is stopped, a one-off container of the same image pulls the copy into its volume, then it
     * starts again. The data brings its own password and settings.
     */
    public function transferIn(string $id, string $name, string $source, string $code, bool $final, callable $progress): void
    {
        if (!self::validId($id)) throw new \InvalidArgumentException('The identifier is 2 to 30 lower-case letters, digits or dashes, starting with a letter.');
        $s = $this->state();
        $s['instances'][$id] = ($s['instances'][$id] ?? []) + ['name' => mb_substr(trim($name), 0, 80), 'email' => '', 'memory_mb' => $s['settings']['memory_mb'], 'cpus' => $s['settings']['cpus'],
            'created' => time(), 'password_hash' => '', 'reset' => '', 'timezone' => 'Europe/Paris'];
        $this->save($s);
        $progress('stopping', "Stopping $id…");
        $this->removeContainer($id);
        $this->ensureNetwork(self::PREFIX . 'i-' . $id);
        [$code0] = $this->docker('GET', '/volumes/' . self::PREFIX . $id);
        if ($code0 === 404) $this->ok($this->docker('POST', '/volumes/create', ['Name' => self::PREFIX . $id, 'Labels' => ['talixio.instance' => $id]]), 'volume');
        $job = self::PREFIX . 'transfer-' . $id;
        $this->docker('DELETE', "/containers/$job", null, ['force' => 'true']);
        $cmd = ['transfer-pull', $source, $code, '--url', rtrim($this->url($id), '/')];
        if ($final) $cmd[] = '--final';
        $this->ok($this->docker('POST', '/containers/create', ['Image' => $s['image']['current'], 'Cmd' => $cmd, 'Labels' => ['talixio.managed' => '1'],
            'HostConfig' => ['Mounts' => [['Type' => 'volume', 'Source' => self::PREFIX . $id, 'Target' => '/data']], 'NetworkMode' => self::PREFIX . 'i-' . $id,
                'ReadonlyRootfs' => true, 'Tmpfs' => ['/tmp' => 'rw,nosuid,nodev,size=256m'], 'CapDrop' => ['ALL'], 'SecurityOpt' => ['no-new-privileges'],
                'Memory' => 512 * 1048576, 'PidsLimit' => 64]], ['name' => $job]), 'create the transfer');
        $this->ok($this->docker('POST', "/containers/$job/start"), 'start the transfer');
        $progress('copying', "Copying from $source…");
        $log = ''; $exit = null; $shown = '';
        for ($t = 0; $t < 6 * 3600; $t += 5) {
            [, $c] = $this->docker('GET', "/containers/$job/json");
            [, $raw] = $this->docker('GET', "/containers/$job/logs", null, ['stdout' => 1, 'stderr' => 1]);
            $log = self::demux(is_string($raw) ? $raw : '');
            $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
            if ($lines && end($lines) !== $shown) $progress('copying', $shown = end($lines));
            if (($c['State']['Status'] ?? '') === 'exited') { $exit = (int)$c['State']['ExitCode']; break; }
            sleep(5);
        }
        $this->docker('DELETE', "/containers/$job", null, ['force' => 'true']);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
        $last = $lines ? end($lines) : '';
        if ($exit !== 0) {
            $this->createAndStart($id, $this->state()); // what was there before starts again (unchanged when the copy failed)
            throw new \RuntimeException($exit === null ? 'The copy took more than 6 hours: stopped.' : 'The copy failed: ' . $last);
        }
        $progress('starting', "Starting $id…");
        $this->createAndStart($id, $this->state());
        $this->reloadCaddy();
        if (!$this->waitHealthy($id, 120)) throw new \RuntimeException("$id does not start after the copy: see its logs (docker logs talixio-$id). The data before the copy is in its volume, in .previous-*.");
        $progress('done', $last . ' Address: ' . $this->url($id));
    }

    /** Docker's multiplexed log stream (8-byte headers) as text. */
    private static function demux(string $raw): string
    {
        $out = '';
        for ($p = 0; $p + 8 <= strlen($raw);) { $len = unpack('N', substr($raw, $p + 4, 4))[1]; $out .= substr($raw, $p + 8, $len); $p += 8 + $len; }
        return $out;
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

    /** The end of an instance's log (errors, warnings, what it logs), without the request noise of php -S unless $all. */
    public function logs(string $id, bool $all = false): string
    {
        $this->need($id);
        [$code, $raw] = $this->docker('GET', '/containers/' . $this->containerName($id) . '/logs', null, ['stdout' => 'true', 'stderr' => 'true', 'tail' => $all ? '500' : '5000', 'timestamps' => 'true'], 20);
        if ($code !== 200 || !is_string($raw)) throw new \RuntimeException("logs: HTTP $code");
        $lines = explode("\n", rtrim(self::demux($raw)));
        // php -S writes Accepted/Closing and one line per request: keep only failed requests
        if (!$all) $lines = array_filter($lines, fn($l) => !preg_match('/\]\s\S+:\d+ (Accepted|Closing)$|\]\s\S+:\d+ \[[23]\d\d\]: /', $l));
        $lines = array_map(fn($l) => preg_replace('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)\.\d+Z/', '$1Z', $l), array_slice(array_values($lines), -300));
        return implode("\n", $lines);
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
                $row += self::healthOf($c);
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
        foreach (@file('/proc/meminfo') ?: [] as $l) if (preg_match('/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)round($m[2] / 1024);
        [, $df] = $this->docker('GET', '/system/df', null, [], 60);
        $vol = []; $volTotal = 0;
        foreach ((is_array($df) ? ($df['Volumes'] ?? []) : []) as $v) {
            $mb = (int)round(max(0, $v['UsageData']['Size'] ?? 0) / 1048576);
            $volTotal += $mb;
            if (str_starts_with($v['Name'], self::PREFIX)) $vol[substr($v['Name'], strlen(self::PREFIX))] = $mb;
        }
        $cache = 0; foreach ((is_array($df) ? ($df['BuildCache'] ?? []) : []) as $b) $cache += (int)($b['Size'] ?? 0);
        $disk = @disk_total_space('/') ? ['total_gb' => round(disk_total_space('/') / 1e9, 1), 'free_gb' => round(disk_free_space('/') / 1e9, 1),
            'images_gb' => round((int)(is_array($df) ? ($df['LayersSize'] ?? 0) : 0) / 1e9, 1), 'volumes_gb' => round($volTotal / 1000, 1), 'cache_gb' => round($cache / 1e9, 1)] : null;
        [, $info] = $this->docker('GET', '/info');
        $model = ''; foreach (@file('/proc/cpuinfo') ?: [] as $l) if (preg_match('/^model name\s*:\s*(.+)$/', $l, $m)) { $model = trim($m[1]); break; }
        $load = array_map('floatval', array_slice(explode(' ', (string)@file_get_contents('/proc/loadavg')), 0, 3));
        $cpu = ['cores' => (int)(is_array($info) ? ($info['NCPU'] ?? 0) : 0) ?: (int)trim((string)shell_exec('nproc')), 'model' => $model, 'load' => $load, 'busy_pct' => self::cpuBusy()];
        $host = ['os' => is_array($info) ? (string)($info['OperatingSystem'] ?? '') : '', 'kernel' => is_array($info) ? (string)($info['KernelVersion'] ?? '') : '',
            'docker' => is_array($info) ? (string)($info['ServerVersion'] ?? '') : '', 'uptime_s' => (int)(float)@file_get_contents('/proc/uptime')];
        $out = ['memory_mb' => $mem, 'volumes_mb' => $vol, 'disk' => $disk, 'cpu' => $cpu, 'host' => $host, 'image' => $this->state()['image'], 'ollama_models' => $this->ollamaModels(), 'git_branch' => getenv('TALIXIO_GIT_BRANCH') ?: 'main', 'services' => $this->services()];
        $out['sizing'] = self::sizing($out, $this->state());
        return $out;
    }

    /** Restarts, out-of-memory stops and the last health checks of a container (from its inspect data). */
    public static function healthOf(array $c): array
    {
        $log = $c['State']['Health']['Log'] ?? [];
        $last = $log ? end($log) : null;
        $at = fn(string $ts) => preg_match('/^(.+?)(?:\.(\d+))?(Z|[+-]\d\d:\d\d)$/', $ts, $m) ? strtotime($m[1] . $m[3]) + (float)('0.' . ($m[2] ?: '0')) : null; // Docker: nanoseconds
        $ms = fn(?array $l) => $l && !empty($l['Start']) && !empty($l['End']) && ($a = $at($l['Start'])) !== null && ($b = $at($l['End'])) !== null ? (int)round(($b - $a) * 1000) : null;
        $times = array_values(array_filter(array_map($ms, $log), fn($v) => $v !== null));
        return ['restarts' => (int)($c['RestartCount'] ?? 0), 'oom' => !empty($c['State']['OOMKilled']), 'failing' => (int)($c['State']['Health']['FailingStreak'] ?? 0),
            'check_ms' => $times ? (int)round(array_sum($times) / count($times)) : null,
            'check_error' => $last && (int)($last['ExitCode'] ?? 0) !== 0 ? mb_substr(trim((string)($last['Output'] ?? '')), 0, 200) : ''];
    }

    /** The shared containers: running, healthy, answering (and in how long), restarts, memory. */
    public function services(): array
    {
        $probe = function (string $url): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_NOBODY => false]);
            $t = microtime(true); curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
            return ['answers' => $code > 0 && $code < 500, 'http' => $code, 'ms' => (int)round((microtime(true) - $t) * 1000), 'error' => $code ? '' : $err];
        };
        $list = [
            'caddy' => ['role' => 'HTTPS and addresses', 'probe' => 'http://' . self::CADDY . ':80/'],
            'ollama' => ['role' => 'Search by meaning (shared)', 'probe' => 'http://' . self::CADDY . ':11434/api/version'],
            'agent' => ['role' => 'Runs the admin page\'s actions', 'probe' => null],
            'admin' => ['role' => 'This admin page', 'probe' => 'http://talixio-admin:8080/icon.svg'],
        ];
        $out = [];
        foreach ($list as $name => $d) {
            [$code, $c] = $this->docker('GET', "/containers/talixio-$name/json", null, [], 10);
            $row = ['name' => $name, 'role' => $d['role'], 'state' => $code === 200 ? ($c['State']['Status'] ?? '?') : 'missing', 'health' => $code === 200 ? ($c['State']['Health']['Status'] ?? '') : '',
                'mem_used_mb' => null, 'mem_limit_mb' => $code === 200 ? (int)round(($c['HostConfig']['Memory'] ?? 0) / 1048576) : 0];
            if ($code === 200) {
                $row += self::healthOf($c);
                if ($row['state'] === 'running') {
                    [, $st] = $this->docker('GET', "/containers/talixio-$name/stats", null, ['stream' => 'false', 'one-shot' => 'true'], 10);
                    if (is_array($st) && isset($st['memory_stats']['usage'])) $row['mem_used_mb'] = (int)round(($st['memory_stats']['usage'] - ($st['memory_stats']['stats']['inactive_file'] ?? 0)) / 1048576);
                }
            }
            if ($name === 'ollama' && empty($this->state()['settings']['ollama'])) $row['disabled'] = true; // turned off on purpose
            if ($name === 'agent') $row += ['answers' => true, 'ms' => 0, 'http' => 200]; // it is answering this very request
            elseif ($d['probe'] && $row['state'] === 'running') $row += $probe($d['probe']);
            $out[] = $row;
        }
        return $out;
    }

    /** Share of the processors in use over a short moment (whole machine), 0-100. */
    private static function cpuBusy(): ?int
    {
        $read = function () { $f = explode(' ', preg_replace('/\s+/', ' ', trim(strtok((string)@file_get_contents('/proc/stat'), "\n")))); array_shift($f); $f = array_map('intval', $f); return [array_sum($f), ($f[3] ?? 0) + ($f[4] ?? 0)]; };
        [$t1, $i1] = $read(); usleep(300_000); [$t2, $i2] = $read();
        return $t2 > $t1 ? (int)round(100 * (1 - ($i2 - $i1) / ($t2 - $t1))) : null;
    }

    /** Is the server big enough? What the limits promise against what the machine has, and what it shows now. */
    public static function sizing(array $srv, array $state): array
    {
        $people = $state['instances'] ?? []; $set = $state['settings'] ?? [];
        $stack = 256 + 128 + 256 + (!empty($set['ollama']) ? 1536 : 0); // agent, admin, Caddy, Ollama (their limits in compose.yml)
        $os = 1024;                                                        // the system and Docker itself
        $promised = array_sum(array_map(fn($i) => (int)$i['memory_mb'], $people)) + $stack;
        $total = (int)($srv['memory_mb']['MemTotal'] ?? 0); $avail = (int)($srv['memory_mb']['MemAvailable'] ?? 0);
        $cores = (int)($srv['cpu']['cores'] ?? 0); $load = (float)($srv['cpu']['load'][1] ?? 0);
        $cpus = array_sum(array_map(fn($i) => (float)$i['cpus'], $people));
        $checks = [];
        if ($total > 0) {
            $r = ($promised + $os) / $total;
            $checks[] = ['what' => 'memory', 'level' => $r <= 1 ? 'ok' : ($r <= 1.5 ? 'warn' : 'bad'), 'promised_mb' => $promised + $os, 'total_mb' => $total,
                'text' => $r <= 1 ? 'Every assistant can use its full memory limit at the same time.'
                    : ($r <= 1.5 ? 'The limits add up to more than the memory: fine while not everyone is busy at once (transcriptions, big documents).'
                    : 'The limits add up to far more than the memory: assistants may be stopped for lack of memory. Add memory or lower the limits.')];
            if ($avail > 0 && $avail < $total * 0.1) $checks[] = ['what' => 'memory_now', 'level' => 'bad', 'text' => 'Less than 10% of the memory is free right now.'];
        }
        $swapT = (int)($srv['memory_mb']['SwapTotal'] ?? 0); $swapF = (int)($srv['memory_mb']['SwapFree'] ?? 0);
        if ($swapT > 0 && $swapT - $swapF > $swapT * 0.25) $checks[] = ['what' => 'swap', 'level' => 'warn', 'text' => 'The machine uses its swap: it lacks memory at times, and everything slows down.'];
        if ($cores > 0) {
            $checks[] = ['what' => 'cpu', 'level' => $load > $cores ? 'bad' : ($load > $cores * 0.7 ? 'warn' : 'ok'),
                'text' => $load > $cores ? 'The processors were saturated over the last 5 minutes.' : ($load > $cores * 0.7 ? 'The processors are busy (over 70% over 5 minutes).' : 'The processors have room.')];
            if ($cpus > $cores * 2) $checks[] = ['what' => 'cpu_limits', 'level' => 'warn', 'text' => 'The CPU limits add up to more than twice the processors: transcriptions will be slow when several run at once.'];
        }
        if (!empty($srv['disk'])) {
            $free = (float)$srv['disk']['free_gb']; $tot = max(0.1, (float)$srv['disk']['total_gb']);
            $checks[] = ['what' => 'disk', 'level' => $free < 5 || $free < $tot * 0.1 ? 'bad' : ($free < 15 || $free < $tot * 0.2 ? 'warn' : 'ok'),
                'text' => $free < 5 || $free < $tot * 0.1 ? 'The disk is nearly full: updates (a new image is about 2.5 GB) and backups will fail.'
                    : ($free < 15 || $free < $tot * 0.2 ? 'Little free disk left: an update needs about 5 GB, and a transfer copies an assistant\'s data twice.' : 'Enough free disk.')];
            if (($srv['disk']['cache_gb'] ?? 0) >= 2) $checks[] = ['what' => 'build_cache', 'level' => 'info', 'text' => 'Build cache: {gb} GB (docker builder prune frees it).', 'vars' => ['gb' => $srv['disk']['cache_gb']]];
        }
        $each = (int)($set['memory_mb'] ?? 1024);
        $room = $total > 0 ? max(0, intdiv($total - $os - $promised, max(256, $each))) : null;
        $levels = array_column($checks, 'level');
        return ['level' => in_array('bad', $levels, true) ? 'bad' : (in_array('warn', $levels, true) ? 'warn' : 'ok'), 'checks' => $checks,
            'promised_mb' => $promised + $os, 'cpus_promised' => $cpus, 'room_for' => $room, 'per_person_mb' => $each];
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
        $proxied = self::behindProxy();
        $out = "# managed by the Talixio agent: changes here are overwritten\n{\n" . ($proxied
                // another proxy in front does HTTPS: plain HTTP here, and the visitor's address from its headers
                ? "\tauto_https off\n\tservers {\n\t\ttrusted_proxies static private_ranges\n\t}\n"
                : "\temail " . (getenv('ACME_EMAIL') ?: 'admin@' . $dom) . "\n" . ($set['mode'] === 'subdomain' ? "\ton_demand_tls {\n\t\task http://talixio-agent:8090/ask\n\t}\n" : '')) . "}\n\n";
        $site = ($proxied ? ':80' : ($dom === 'localhost' ? 'http://localhost' : $dom)) . " {\n$common$admin";
        if ($set['mode'] === 'path') {
            foreach ($ids as $id) $site .= "\tredir /$id /$id/\n\thandle_path /$id/* {\n\t\treverse_proxy {$this->containerName($id)}:8080\n\t}\n";
        } elseif ($proxied) { // subdomains: the proxy in front sends *.domain here
            foreach ($ids as $id) $site .= "\t@$id host $id.$dom\n\thandle @$id {\n\t\treverse_proxy {$this->containerName($id)}:8080\n\t}\n";
        }
        $out .= $site . $home . "}\n\n";
        if ($set['mode'] === 'subdomain' && $dom !== 'localhost' && !$proxied) {
            $out .= "*.$dom {\n\ttls {\n\t\ton_demand\n\t}\n$common";
            foreach ($ids as $id) $out .= "\t@$id host $id.$dom\n\thandle @$id {\n\t\treverse_proxy {$this->containerName($id)}:8080\n\t}\n";
            $out .= $home . "}\n\n";
        }
        // Ollama for the instances: embeddings only (it has no password: nothing else gets through)
        $out .= "http://:11434 {\n\t@embed path /api/embed /api/embeddings /api/tags /api/version\n\thandle @embed {\n\t\treverse_proxy talixio-ollama:11434\n\t}\n\trespond 403\n}\n";
        return $out;
    }

    /** Another reverse proxy (on another machine) does HTTPS in front of the stack: install-stack.sh --behind-proxy. */
    public static function behindProxy(): bool { return getenv('TALIXIO_BEHIND_PROXY') === '1'; }

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
        $progress('building', "Fetching {$branch}…");
        $src = $this->checkout($url, $branch);
        try {
            $ctx = "$src.tar";
            [$code, $out] = self::run(['tar', '-C', $src, '--exclude=./.git', '--exclude=./data', '--exclude=./research', '-cf', $ctx, '.']);
            if ($code !== 0) throw new \RuntimeException("tar: $out");
            // the dependencies: an image named after base.Dockerfile, built only when that file changed
            $base = self::BASE_IMAGE . ':' . substr(sha1((string)file_get_contents("$src/deploy/docker/base.Dockerfile")), 0, 12);
            if ($this->docker('GET', '/images/' . rawurlencode($base) . '/json')[0] !== 200) {
                $progress('building', "The dependencies changed: building $base (several minutes)…");
                $this->build($ctx, ['t' => $base, 'dockerfile' => 'deploy/docker/base.Dockerfile'], $progress);
            }
            $this->tag($base, self::BASE_IMAGE, 'current');
            $git = fn(string ...$a) => trim(self::run(['git', '-C', $src, ...$a])[1]);
            $progress('building', "Building {$tag} from {$branch}…");
            $this->build($ctx, ['t' => $tag, 'buildargs' => json_encode(['BASE_IMAGE' => $base, 'VERSION_SHA' => $git('rev-parse', 'HEAD'),
                'VERSION_DATE' => $git('log', '-1', '--format=%cI'), 'VERSION_SUBJECT' => $git('log', '-1', '--format=%s')])], $progress);
        } finally {
            self::run(['rm', '-rf', $src, "$src.tar"]);
        }
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
        $this->refreshStack($progress, 'Every instance runs the new version.');
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
        $this->refreshStack($progress, 'Back on the previous version.');
    }

    /**
     * The admin page and the agent run talixio:current too: move them to it last, so the admin page shows the new
     * version. The agent replaces itself: the new one starts under its name, then the old one (running this job) is
     * removed; if the new one does not answer, the old one stays.
     */
    private function refreshStack(callable $progress, string $done): void
    {
        [, $img] = $this->docker('GET', '/images/' . rawurlencode(self::IMAGE . ':current') . '/json');
        $want = is_array($img) ? ($img['Id'] ?? '') : '';
        $admin = self::PREFIX . 'admin';
        [$code, $c] = $this->docker('GET', "/containers/$admin/json");
        if ($code === 200 && $want !== '' && $c['Image'] !== $want) {
            $progress('rolling', 'Moving the admin page to the new version…');
            $this->docker('POST', "/containers/$admin/stop", null, ['t' => 10], 30);
            $this->ok($this->docker('DELETE', "/containers/$admin", null, ['force' => 'true']), "remove $admin");
            try { $this->respawn($c, $admin, self::IMAGE . ':current'); } catch (\Throwable $e) { $this->respawn($c, $admin, $c['Image']); throw $e; }
        }
        $agent = self::PREFIX . 'agent';
        [$code, $c] = $this->docker('GET', "/containers/$agent/json");
        if ($code !== 200 || $want === '' || $c['Image'] === $want) { $progress('done', $done); return; }
        $progress('done', "$done The admin page and its agent restart on it: reload the page in a few seconds.");
        $this->docker('DELETE', "/containers/$agent-old", null, ['force' => 'true']); // left by an earlier try
        $this->ok($this->docker('POST', "/containers/$agent/rename", null, ['name' => "$agent-old"]), 'rename the agent');
        try {
            $this->respawn($c, $agent, self::IMAGE . ':current');
            for ($t = 0; $t < 45; $t++) {
                sleep(2);
                $ch = curl_init("http://$agent:8090/health");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
                $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
                curl_close($ch);
                if ($ok) { $this->docker('DELETE', "/containers/$agent-old", null, ['force' => 'true']); return; } // ends this process too
            }
            throw new \RuntimeException('the new agent did not answer');
        } catch (\Throwable $e) {
            $this->docker('DELETE', "/containers/$agent", null, ['force' => 'true']);
            $this->docker('POST', "/containers/$agent-old/rename", null, ['name' => $agent]);
            $progress('done', "$done The agent stayed on the previous version ({$e->getMessage()}); run install-stack.sh to move it.");
        }
    }

    /** A container like the inspected one $c (same settings, networks and aliases), from $image. */
    private function respawn(array $c, string $name, string $image): void
    {
        $cfg = $c['Config'];
        unset($cfg['Hostname']);
        $cfg['Image'] = $image;
        $cfg['HostConfig'] = $c['HostConfig'];
        $short = substr($c['Id'], 0, 12);
        $eps = [];
        foreach ($c['NetworkSettings']['Networks'] ?? [] as $net => $n)
            $eps[$net] = ['Aliases' => array_values(array_filter($n['Aliases'] ?? [], fn($a) => $a !== $short && $a !== ltrim($c['Name'] ?? '', '/')))];
        $cfg['NetworkingConfig'] = ['EndpointsConfig' => $eps];
        $this->ok($this->docker('POST', '/containers/create', $cfg, ['name' => $name]), "create $name");
        $this->ok($this->docker('POST', "/containers/$name/start"), "start $name");
    }

    private function tag(string $image, string $repo, string $tag): void
    {
        $this->ok($this->docker('POST', '/images/' . rawurlencode($image) . '/tag', null, ['repo' => $repo, 'tag' => $tag]), 'tag', [200, 201]);
    }

    /** The branch, without its history, in a temporary folder. $light: no file contents until read (git show). */
    private function checkout(string $url, string $branch, bool $light = false): string
    {
        $dir = sys_get_temp_dir() . '/talixio-src-' . bin2hex(random_bytes(4));
        $token = getenv('TALIXIO_GIT_TOKEN') ?: '';
        [$code, $out] = self::run(['git', 'clone', '-q', '--depth', '1', ...($light ? ['--filter=blob:none', '--no-checkout'] : []), '--branch', $branch, '--', $url, $dir], self::gitEnv());
        if ($code !== 0) {
            self::run(['rm', '-rf', $dir]);
            $out = mb_substr(str_replace(array_filter([$url, $token]), ['<repository>', '<token>'], $out), 0, 300);
            if ($token === '' && preg_match('/could not read Username|Authentication failed|terminal prompts disabled/i', $out))
                $out = 'the repository is private and the stack has no credentials for it. Put a read-only token in '
                    . 'GIT_TOKEN in deploy/docker/.env on the server, then run sudo deploy/docker/install-stack.sh once. (' . trim($out) . ')';
            throw new \RuntimeException('Could not fetch the code: ' . $out);
        }
        return $dir;
    }

    private static function gitEnv(): array
    {
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $token = getenv('TALIXIO_GIT_TOKEN') ?: '';
        if ($token !== '') // a private repository: the credentials as a header, out of the address and the command line
            $env += ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'http.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode((getenv('TALIXIO_GIT_USER') ?: 'x-access-token') . ":$token")];
        return $env;
    }

    /**
     * What runs (read in a running assistant) and the latest commit of the branch, with the changelog entries in
     * between. The branch's files are fetched again only when its last commit changed.
     */
    public function checkUpdate(string $branch): array
    {
        $url = getenv('TALIXIO_GIT_URL') ?: '';
        if ($url === '') throw new \RuntimeException('TALIXIO_GIT_URL is not set (in the stack\'s .env).');
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) throw new \InvalidArgumentException('bad branch name');
        $current = null;
        foreach ($this->list() as $i) {
            if ($i['state'] !== 'running') continue;
            [$c1, $v] = $this->exec($this->containerName($i['id']), ['cat', '/opt/talixio/VERSION.json'], 20);
            [$c2, $n] = $this->exec($this->containerName($i['id']), ['cat', '/opt/talixio/VERSION'], 20);
            $v = $c1 === 0 ? (json_decode($v, true) ?: []) : [];
            $current = ['sha' => (string)($v['sha'] ?? ''), 'date' => (string)($v['date'] ?? ''), 'subject' => (string)($v['subject'] ?? ''), 'number' => $c2 === 0 ? trim($n) : ''];
            break;
        }
        [$code, $out] = self::run(['git', 'ls-remote', '--', $url, "refs/heads/$branch"], self::gitEnv());
        if ($code !== 0) $this->checkout($url, $branch, true); // throws with the explanation (private repository…)
        $sha = (string)strtok($out, "\t");
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new \RuntimeException("No branch $branch in the repository.");
        $cache = "$this->stateDir/latest.json";
        $latest = json_decode((string)@file_get_contents($cache), true) ?: [];
        if (($latest['sha'] ?? '') !== $sha) {
            $src = $this->checkout($url, $branch, true);
            try {
                $git = fn(string ...$a) => self::run(['git', '-C', $src, ...$a], self::gitEnv())[1];
                $latest = ['sha' => $sha, 'number' => trim($git('show', 'HEAD:VERSION')), 'date' => trim($git('log', '-1', '--format=%cI')),
                    'subject' => trim($git('log', '-1', '--format=%s')), 'changelog' => $git('show', 'HEAD:CHANGELOG.md')];
            } finally {
                self::run(['rm', '-rf', $src]);
            }
            @file_put_contents($cache, json_encode($latest));
        }
        // the changelog's entries newer than what runs
        $changes = [];
        foreach (preg_split('/^(?=## )/m', (string)($latest['changelog'] ?? '')) as $sec) {
            if (!preg_match('/^## (\d+\.\d+\.\d+)/', $sec, $m)) continue;
            if ($current && $current['number'] !== '' && version_compare($m[1], $current['number'], '<=')) break;
            $changes[] = trim($sec);
            if (count($changes) >= 15) break;
        }
        unset($latest['changelog']);
        return ['branch' => $branch, 'current' => $current, 'latest' => $latest, 'checked_at' => time(),
            'new' => $current === null || $current['sha'] !== $sha, 'changes' => mb_substr(implode("\n\n", $changes), 0, 12000)];
    }

    /** [exit code, output] of a fixed command. */
    private static function run(array $cmd, array $env = []): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env ? $env + getenv() : null);
        if (!is_resource($p)) return [127, 'cannot start ' . $cmd[0]];
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        return [proc_close($p), (string)$out];
    }

    /** Build an image from a tar of the sources (the classic builder of the Docker API: its cache is kept between updates). */
    private function build(string $context, array $query, callable $progress): void
    {
        $ch = curl_init('http://docker/v1.44/build?' . http_build_query($query + ['rm' => '1', 'forcerm' => '1']));
        $buf = ''; $err = null;
        $fh = fopen($context, 'rb');
        curl_setopt_array($ch, [CURLOPT_UNIX_SOCKET_PATH => $this->socket, CURLOPT_POST => true, CURLOPT_TIMEOUT => 3600,
            CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($context),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-tar', 'Expect:'],
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
        curl_close($ch); fclose($fh);
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

    /** Ollama: the model the instances use for embeddings (downloaded once, shared), or another one people chose in their Settings. */
    public function pullModel(?string $model = null): string
    {
        $m = $model ?: $this->state()['settings']['ollama_model'];
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,100}$/i', $m)) throw new \InvalidArgumentException('Unexpected model name.');
        if ($this->ollamaState() !== 'running') throw new \RuntimeException('The shared Ollama is stopped: turn it on first (Settings → Shared memory search).');
        [$code, $out] = $this->exec('talixio-ollama', ['ollama', 'pull', $m], 1800);
        if ($code !== 0) throw new \RuntimeException("ollama pull $m: " . trim(mb_substr($out, -300)));
        return "$m ready";
    }

    /** The shared Ollama: turned off in the admin page = stopped, its memory and processors go to the assistants. */
    public function setOllama(bool $on): void
    {
        $this->ok($this->docker('POST', '/containers/talixio-ollama/' . ($on ? 'start' : 'stop'), null, [], 60), ($on ? 'start' : 'stop') . ' Ollama', [204, 304]);
    }

    public function ollamaState(): string
    {
        [$code, $c] = $this->docker('GET', '/containers/talixio-ollama/json', null, [], 10);
        return $code === 200 ? (string)($c['State']['Status'] ?? '?') : 'missing';
    }

    /** Models downloaded in the shared Ollama (when it runs). */
    public function ollamaModels(): array
    {
        if ($this->ollamaState() !== 'running') return [];
        try {
            [$code, $out] = $this->exec('talixio-ollama', ['ollama', 'list'], 20);
        } catch (\Throwable) { return []; }
        if ($code !== 0) return [];
        $rows = array_slice(array_filter(explode("\n", trim($out))), 1); // NAME  ID  SIZE  MODIFIED
        return array_values(array_map(fn($l) => ['name' => preg_split('/\s+/', trim($l))[0], 'size' => preg_match('/\s(\d+(?:\.\d+)?\s?[KMG]B)\s/', $l, $m) ? $m[1] : ''], $rows));
    }

    public static function password(): string { return substr(strtr(base64_encode(random_bytes(18)), '+/=', 'xyz'), 0, 20); }
}
