<?php
declare(strict_types=1);
// Pages that must work on a brand-new installation, before any mail is sorted or any connector is set up (audit Q8, Q9).
require_once __DIR__ . '/../helpers.php';

$d = test_dir('freshbase');
$web = web_app($d, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery']);
check('freshbase: sign in', $web('/api/login', ['password' => 'correct horse battery'])['_code'] === 200);
$c = $web('/api/contacts', ['name' => 'Ada Lovelace', 'email' => 'ada@example.org']);
check('freshbase: a contact is saved', $c['_code'] === 200 && ($c['contact']['name'] ?? '') === 'Ada Lovelace', $c['_raw']);
$p = $web('/api/contacts/' . (int)($c['contact']['id'] ?? 0));
check('freshbase: a contact\'s page opens before any mail is sorted', $p['_code'] === 200 && ($p['emails'] ?? null) === [], $p['_raw']);
$e = $web('/api/contacts/' . (int)($c['contact']['id'] ?? 0), ['notes' => 'met at the conference']);
check('freshbase: a contact is updated', $e['_code'] === 200 && ($e['contact']['notes'] ?? '') === 'met at the conference', $e['_raw']);
$l = $web('/api/legifrance');
check('freshbase: the French law card answers', $l['_code'] === 200 && ($l['configured'] ?? null) === false, $l['_raw']);
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
