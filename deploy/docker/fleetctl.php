<?php
declare(strict_types=1);
// Agent commands for the install script (run inside the agent container):
//   php fleetctl.php init                 write the Caddyfile (agent start)
//   php fleetctl.php adopt <id> [name]    an existing installation, already copied into the volume talixio-<id>
//   php fleetctl.php create <id> [name]   a new person (prints the address and first password)
//   php fleetctl.php reload               rewrite the Caddyfile and reload Caddy
//   php fleetctl.php list
require __DIR__ . '/../../src/autoload.php';

$fleet = new Talixio\Fleet(getenv('TALIXIO_STATE_DIR') ?: '/state');
[, $cmd, $id, $name] = $argv + [1 => '', 2 => '', 3 => ''];
try {
    switch ($cmd) {
        case 'init':
            $dir = getenv('CADDY_CONFIG_DIR') ?: '/caddy-config';
            if (is_dir($dir) && is_writable($dir)) file_put_contents("$dir/Caddyfile", $fleet->caddyfile());
            try { $fleet->reloadCaddy(); } catch (Throwable) {} // Caddy may not be up yet: it reads the file when it starts
            break;
        case 'adopt': echo json_encode($fleet->adopt($id, $name)), "\n"; break;
        case 'create': $r = $fleet->create($id, $name); echo "Address: {$r['url']}\nPassword: {$r['password']}\n"; break;
        case 'reload': $fleet->reloadCaddy(); echo "Caddy reloaded\n"; break;
        case 'list': foreach ($fleet->list() as $i) echo "{$i['id']}\t{$i['state']}\t{$i['url']}\n"; break;
        default: fwrite(STDERR, "usage: fleetctl.php init|adopt|create|reload|list\n"); exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
