<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers.php';
$d = test_dir('smoke');
$web = web_app($d, ['TALIXIO_WEB_PASSWORD' => 'pw']);
check('smoke: sign in', $web('/api/login', ['password' => 'pw'])['_code'] === 200);
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
