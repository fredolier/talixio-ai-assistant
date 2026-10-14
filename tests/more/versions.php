<?php
declare(strict_types=1);
// File versions and unique names (src/Files.php replace/keepVersion/versions, src/Library.php taken/freeName/replaceWith/mergeDuplicates).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Conflict};

$d = test_dir('versions');
$envKeys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$envSaved = array_combine($envKeys, array_map(fn($k) => getenv($k), $envKeys));
putenv("TALIXIO_DATA_DIR=$d/app"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv();
$files = $app->files(); $lib = $app->library(); $st = $app->store;
$body = fn(int $id) => (string)file_get_contents($files->get($id)['path']);

// ---- save: keep both by default, replace in place on request ----
$a = $files->save(tap_tmp('one'), 'Report.txt', 'text/plain', '/Projects');
$b = $files->save(tap_tmp('two'), 'report.txt', 'text/plain', '/Projects');
check('versions: a second save under a taken name keeps both, numbered', $b !== $a && $files->get($b)['name'] === 'report (2).txt');
$c = $files->save(tap_tmp('three'), 'Report.txt', 'text/plain', '/Projects', true);
$v = $files->versions($a);
check('versions: a save with replace updates the same file and keeps a version', $c === $a && $body($a) === 'three' && count($v) === 1
    && file_get_contents($files->version($a, $v[0]['id'])['path']) === 'one');
$files->replace($a, tap_tmp('three'));
check('versions: the same content again makes no version', count($files->versions($a)) === 1);
check('versions: freeName goes on to (3)', $lib->freeName('/Projects', 'Report.txt') === 'Report (3).txt' && $lib->taken('/Projects', 'REPORT.TXT') === $a);

// ---- restore ----
$f = $files->restoreVersion($a, $v[0]['id']);
$v2 = $files->versions($a);
check('versions: a restore brings the content back and keeps the one it replaced', $body($a) === 'one' && count($v2) === 1
    && file_get_contents($files->version($a, $v2[0]['id'])['path']) === 'three' && (int)$f['id'] === $a);
try { $files->restoreVersion($a, 99999); $e = ''; } catch (\InvalidArgumentException $x) { $e = $x->getMessage(); }
check('versions: an unknown version is refused', $e !== '');

// ---- at most VERSIONS_MAX ----
for ($i = 0; $i < \Talixio\Files::VERSIONS_MAX + 3; $i++) $files->replace($a, tap_tmp("v$i"));
check('versions: at most VERSIONS_MAX are kept', count($files->versions($a)) === \Talixio\Files::VERSIONS_MAX);
$q = $files->save(tap_tmp('q1'), 'quiet.md', 'text/markdown', '/Projects');
$files->replace($q, tap_tmp('q2'), '', true); $files->replace($q, tap_tmp('q3'), '', true);
check('versions: a burst of quiet saves keeps one version', count($files->versions($q)) === 1 && $body($q) === 'q3');

// ---- rename and move onto a taken name ----
try { $lib->renameFile($b, 'Report.txt', null); $e = ''; } catch (Conflict $x) { $e = $x->getMessage(); }
check('versions: a rename onto a taken name asks (Conflict)', str_contains($e, 'Report.txt'));
$r = $lib->renameFile($b, 'Report.txt', false);
check('versions: keep both renames to a free name', $r['name'] === 'Report (3).txt');
$before = count($files->versions($a));
$r = $lib->renameFile($b, 'Report.txt', true);
check('versions: replace gives the content to the other file, whose id stays', (int)$r['id'] === $a && $body($a) === 'two'
    && count($files->versions($a)) === min($before + 1, \Talixio\Files::VERSIONS_MAX));
check('versions: the id of the file that went leads to the one that took its content', (int)($files->get($b)['id'] ?? 0) === $a);
$m = $files->save(tap_tmp('elsewhere'), 'Report.txt', 'text/plain', '/Archive');
try { $lib->moveFile($m, '/Projects', null); $e = ''; } catch (Conflict $x) { $e = $x->getMessage(); }
check('versions: a move onto a taken name asks', $e !== '');
$r = $lib->moveFile($m, '/Projects', false);
check('versions: a move keeping both numbers the name', $r['folder'] === '/Projects' && $r['name'] !== 'Report.txt' && str_starts_with($r['name'], 'Report ('));
$m2 = $files->save(tap_tmp('moved in'), 'Report.txt', 'text/plain', '/Archive');
$r = $lib->moveFile($m2, '/Projects', true);
check('versions: a move with replace updates the file there', (int)$r['id'] === $a && $body($a) === 'moved in');
check('versions: moving to the same folder changes nothing', $lib->moveFile($a, '/Projects', null)['id'] == $a);

// ---- notes: saving under a name updates the note ----
$n1 = $lib->saveNote('/Notes', 'Plan.md', "# Plan\nfirst");
$n2 = $lib->saveNote('/Notes', 'Plan.md', "# Plan\nsecond");
check('versions: a note saved again under its name is the same file', (int)$n1['id'] === (int)$n2['id'] && str_contains($body((int)$n1['id']), 'second')
    && count($files->versions((int)$n1['id'])) === 1);

// ---- the trash, purge ----
$t1 = $files->save(tap_tmp('t1'), 'gone.txt', 'text/plain', '/Projects');
$lib->deleteFile($t1);
$t2 = $files->save(tap_tmp('t2'), 'gone.txt', 'text/plain', '/Projects');
check('versions: a name in the trash is free again', $files->get($t2)['name'] === 'gone.txt');
$lib->restore($t1);
check('versions: a restore onto a taken name gets a number', $files->get($t1)['name'] === 'gone (2).txt');
$files->replace($t2, tap_tmp('t2b'));
$vp = $files->version($t2, $files->versions($t2)[0]['id'])['path'];
$lib->deleteFile($t2); $lib->purgeFile($t2);
check('versions: deleting a file for good removes its versions', !is_file($vp) && $st->q('SELECT COUNT(*) FROM file_versions WHERE file_id = ?', [$t2])->fetchColumn() == 0);
check('versions: purgeVersions by age', $files->purgeVersions(null, 0) === 0);

// ---- the one-time merge of duplicates ----
$x1 = $files->save(tap_tmp('old'), 'dup.txt', 'text/plain', '/Merge');
$x2 = $files->save(tap_tmp('new'), 'dup2.txt', 'text/plain', '/Merge');
$st->q("UPDATE files SET name = 'dup.txt', created = created - 100 WHERE id = ?", [$x1]); // as before 0.28.29, when a name could be used twice
$st->q("UPDATE files SET name = 'DUP.txt' WHERE id = ?", [$x2]);
$merged = $lib->mergeDuplicates();
$xv = $files->versions($x2);
check('versions: duplicates merge into the newest, the others become its versions', $merged === 1 && $body($x2) === 'new' && count($xv) === 1
    && file_get_contents($files->version($x2, $xv[0]['id'])['path']) === 'old' && (int)$files->get($x1)['id'] === $x2);
check('versions: nothing left to merge the second time', $lib->mergeDuplicates() === 0);

// ---- the assistant's tools ----
$x = fn(string $n, array $args = []) => $app->tools->execute($n, $args);
$k = $files->save(tap_tmp('k1'), 'kept.txt', 'text/plain', '/Tools test');
check('versions tool: none yet', str_contains($x('kb_versions', ['id' => $k]), 'no earlier versions'));
$files->replace($k, tap_tmp('k2'));
$list = $x('kb_versions', ['id' => $k]);
$kv = $files->versions($k)[0]['id'];
check('versions tool: lists them', str_contains($list, "version $kv:"), $list);
$out = $x('kb_versions', ['id' => $k, 'action' => 'restore', 'version' => $kv]);
check('versions tool: restores one', str_contains($out, 'restored') && $body($k) === 'k1', $out);
$k2 = $files->save(tap_tmp('other'), 'kept.txt', 'text/plain', '/Elsewhere');
$out = $x('kb_move', ['id' => $k2, 'folder' => '/Tools test']);
check('versions tool: kb_move keeps both by default', str_contains($out, 'kept (2).txt') && $body($k) === 'k1', $out);
$k3 = $files->save(tap_tmp('third'), 'kept.txt', 'text/plain', '/Elsewhere');
$out = $x('kb_move', ['id' => $k3, 'folder' => '/Tools test', 'replace' => true]);
check('versions tool: kb_move with replace updates the file there', str_contains($out, "file #$k") && $body($k) === 'third', $out);

foreach ($envSaved as $k => $val) putenv($val === false ? $k : "$k=$val");
