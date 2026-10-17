<?php
declare(strict_types=1);
// Front controller for `php -S ... -t web/public web/router.php`.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// the app's scripts and styles under their version (v/<version>/app.js): a new address for every version
if (preg_match('#^/v/[0-9a-f]{12}/((?:i18n/)?[a-z0-9]+\.(js|css))$#', $path, $m) && is_file(__DIR__ . '/public/' . $m[1])) {
    header('Content-Type: ' . ($m[2] === 'css' ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/public/' . $m[1]);
    return true;
}
// assistants under one address (/fred-work/, /fred-pia/) linked together: the installed app covers the whole address, so
// switching to another one stays in the app's window instead of showing it as an outside page with an address bar
if ($path === '/manifest.json' && trim((string)getenv('TALIXIO_BASE_PATH'), '/') !== '' && json_decode((string)getenv('TALIXIO_LINKS'), true)) {
    $m = json_decode((string)file_get_contents(__DIR__ . '/public/manifest.json'), true);
    $m['scope'] = '/';
    header('Content-Type: application/manifest+json');
    header('Cache-Control: no-cache');
    echo json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return true;
}
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
