<?php
declare(strict_types=1);
// The Talixio agent (multi-user server): the only service with access to Docker. Reached by the
// admin page (with a shared token) and by Caddy (/ask, for certificates in subdomain mode), on the
// internal control network only. Every route maps to one fixed action of Talixio\Fleet.
//   php -S 0.0.0.0:8090 /opt/talixio/deploy/docker/agent.php
require __DIR__ . '/../../src/autoload.php';

use Talixio\Fleet;

ini_set('display_errors', '0');
set_time_limit(0);
$state = getenv('TALIXIO_STATE_DIR') ?: '/state';
$fleet = new Fleet($state);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$json = function (mixed $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// Caddy, before getting a certificate for a subdomain: yes only for existing instances
if ($path === '/ask') { http_response_code($fleet->ask(strtolower((string)($_GET['domain'] ?? ''))) ? 200 : 404); return; }
if ($path === '/health') { $json(['ok' => true]); return; }

$token = (string)getenv('TALIXIO_AGENT_TOKEN');
if (strlen($token) < 32 || !hash_equals($token, (string)($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''))) { $json(['error' => 'forbidden'], 403); return; }
$b = json_decode((string)file_get_contents('php://input'), true) ?: [];

/** Long actions (update, rollback, model download) run on their own; the admin page follows them in job.json. */
$background = function (string $kind, string $arg = '') use ($state, $json, $fleet): void {
    $job = $fleet->job();
    if (($job['state'] ?? '') === 'running') { $json(['error' => 'Another long action is running: ' . ($job['kind'] ?? '')], 409); return; }
    file_put_contents("$state/job.json", json_encode(['kind' => $kind, 'state' => 'running', 'message' => 'Starting…', 'log' => [], 'started' => time(), 'updated' => time()]));
    Talixio\Background::start([PHP_BINARY, __DIR__ . '/fleet-job.php', $kind, $arg], "$state/job.out");
    $json(['ok' => true]);
};

try {
    $m = [];
    match (true) {
        $path === '/instances' && $method === 'GET' => $json(['instances' => $fleet->list(), 'settings' => Fleet::publicSettings($fleet->state()['settings'])]),
        $path === '/instances' && $method === 'POST' => $json($fleet->create((string)($b['id'] ?? ''), (string)($b['name'] ?? ''), (string)($b['email'] ?? ''),
            isset($b['memory_mb']) ? (int)$b['memory_mb'] : null, isset($b['cpus']) ? (float)$b['cpus'] : null)),
        (bool)preg_match('#^/instances/([a-z0-9-]+)/(start|stop|restart|recreate|reset-password|reset-totp|doctor|logs|backup|limits|links|remove)$#', $path, $m) && $method === 'POST' => (function () use ($fleet, $m, $b, $json) {
            [, $id, $act] = $m;
            $r = match ($act) {
                'start' => $fleet->start($id), 'stop' => $fleet->stop($id), 'restart' => $fleet->restart($id), 'recreate' => $fleet->recreate($id),
                'reset-password' => ['password' => $fleet->resetPassword($id)],
                'reset-totp' => $fleet->resetTotp($id),
                'doctor' => ['text' => $fleet->doctor($id)],
                'logs' => ['text' => $fleet->logs($id, !empty($b['all']))],
                'backup' => ['text' => $fleet->backup($id)],
                'limits' => $fleet->setLimits($id, (int)($b['memory_mb'] ?? 1024), (float)($b['cpus'] ?? 1.5)),
                'links' => ['links' => $fleet->setLinks($id, array_map('strval', (array)($b['links'] ?? [])))],
                'remove' => $fleet->remove($id, !empty($b['delete_data'])),
            };
            $json(is_array($r) ? $r : ['ok' => true]);
        })(),
        $path === '/settings' && $method === 'POST' => (function () use ($fleet, $b, $json) {
            $before = $fleet->state()['settings'];
            $after = $fleet->setSettings($b);
            $fleet->reloadCaddy();
            if (($before['ollama'] ?? true) !== $after['ollama']) { try { $fleet->setOllama($after['ollama']); } catch (\Throwable $e) { error_log('ollama: ' . $e->getMessage()); } }
            // what instances get from these settings changed: make their containers again
            $keys = ['mode', 'ollama', 'ollama_model', 'google_client_id', 'google_client_secret', 'apparmor_unconfined', 'domain', 'eu_provider', 'eu_key', 'eu_url', 'eu_only'];
            $redo = array_filter($keys, fn($k) => ($before[$k] ?? null) !== ($after[$k] ?? null));
            if ($redo) foreach (array_keys($fleet->state()['instances']) as $id) $fleet->recreate($id);
            $json(['settings' => Fleet::publicSettings($after), 'recreated' => (bool)$redo]);
        })(),
        $path === '/server' => $json($fleet->server()),
        $path === '/caddyfile' => $json(['caddyfile' => $fleet->caddyfile()]),
        $path === '/check-update' => $json($fleet->checkUpdate((string)($_GET['branch'] ?? (getenv('TALIXIO_GIT_BRANCH') ?: 'main')))),
        $path === '/job' => $json($fleet->job()),
        $path === '/update' && $method === 'POST' => $background('update', (string)($b['branch'] ?? (getenv('TALIXIO_GIT_BRANCH') ?: 'main'))),
        $path === '/rollback' && $method === 'POST' => $background('rollback'),
        $path === '/pull-model' && $method === 'POST' => $background('pull-model', (string)($b['model'] ?? '')),
        $path === '/transfer' && $method === 'POST' => (function () use ($background, $b) {
            if (!Fleet::validId((string)($b['id'] ?? ''))) throw new \InvalidArgumentException('The identifier is 2 to 30 lower-case letters, digits or dashes, starting with a letter.');
            if (!preg_match('#^https://\S+$#', (string)($b['source'] ?? '')) || !preg_match('/^tx-[0-9a-f]{40}$/', (string)($b['code'] ?? ''))) throw new \InvalidArgumentException('The old assistant\'s address (https://…) and its transfer code (tx-…) are needed.');
            $background('transfer', json_encode(array_intersect_key($b, array_flip(['id', 'name', 'source', 'code', 'final']))));
        })(),
        default => $json(['error' => 'not found'], 404),
    };
} catch (\InvalidArgumentException $e) {
    $json(['error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('agent: ' . $e->getMessage());
    $json(['error' => $e->getMessage()], 500);
}
