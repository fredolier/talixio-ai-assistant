<?php
declare(strict_types=1);
// Front controller for `php -S ... -t web/public web/router.php`.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . '/public' . $path) && !str_contains($path, '..')) {
    return false; // static asset, served by the built-in server
}
// Warnings must never leak into JSON responses: log them (journald) instead of printing them.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
set_time_limit(0); // model calls with tools can take minutes
require __DIR__ . '/../src/autoload.php';
(new Talixio\Web(Talixio\App::fromEnv()))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
