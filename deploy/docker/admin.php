<?php
declare(strict_types=1);
// The admin page (multi-user server):  php -S 0.0.0.0:8080 admin.php   ("php admin.php --boot" prints the setup code)
require __DIR__ . '/../../src/autoload.php';

ini_set('display_errors', '0');
$admin = new Talixio\AdminWeb(getenv('TALIXIO_ADMIN_DIR') ?: '/admin-data', getenv('TALIXIO_AGENT_URL') ?: 'http://talixio-agent:8090', (string)getenv('TALIXIO_AGENT_TOKEN'));
if (PHP_SAPI === 'cli') { $admin->boot(); return; }
$admin->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
