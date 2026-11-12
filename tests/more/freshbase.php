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
// audit Q4: the Privacy page's overview raised a PHP warning on every load
putenv("TALIXIO_DATA_DIR=$d/inproc"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$warnings = [];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (error_reporting() & $no) $warnings[] = "$msg ($file:$line)"; return true; }); // @-silenced reads of absent files are on purpose
$ov = \Talixio\App::fromEnv()->privacy()->overview();
restore_error_handler();
putenv('TALIXIO_DATA_DIR');
check('freshbase: the Privacy overview loads without a PHP warning', $warnings === [] && isset($ov['level']), implode(' | ', $warnings));
// audit Q25: an app error keeps its message for the browser, without the server's file paths
check('freshbase: an error message loses the server paths, not the web addresses', \Talixio\Web::internalError(new RuntimeException('the backup folder /var/lib/talixio/backups cannot be created (see https://example.org/help/backups)'))
    === 'the backup folder …/backups cannot be created (see https://example.org/help/backups)');
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
