<?php
declare(strict_types=1);
// Front controller for `php -S ... -t web/public web/router.php`.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . '/public' . $path) && !str_contains($path, '..')) {
    return false; // static asset, served by the built-in server
}
require __DIR__ . '/../src/autoload.php';
(new Talixio\Web(Talixio\App::fromEnv()))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
