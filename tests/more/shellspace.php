<?php
declare(strict_types=1);
// Audit S7: the sandboxed shell follows the spaces' confidentiality: which files it may copy in, what that raises,
// where its results may be saved, and one /work per space. Uses a stand-in for bubblewrap (no isolation: test only).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Privacy, Shell};

$d = test_dir('shellspace');
// runs the command in the folder bound to /work, like bubblewrap's --chdir /work, without any isolation
file_put_contents("$d/fake-bwrap", "#!/bin/sh\nwhile [ \"\$1\" != \"/bin/sh\" ]; do if [ \"\$1\" = --bind ] && [ \"\$3\" = /work ]; then cd \"\$2\"; fi; shift; done\nexec \"\$@\"\n"); chmod("$d/fake-bwrap", 0755);
Shell::$binaryOverride = "$d/fake-bwrap";
$keep = [];
foreach (['TALIXIO_DATA_DIR' => "$d/data", 'TALIXIO_PROVIDER' => 'claude-code', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_SHELL' => 'auto'] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv();
$conf = $app->spaces()->save(['name' => 'Client Iso', 'folder' => '/Clients/Iso', 'privacy' => 'confidential']);
$sec = $app->spaces()->save(['name' => 'Vault', 'folder' => '/Vault', 'privacy' => 'secret']);
$confFile = $app->files()->save(tap_tmp('the Iso contract terms'), 'contract.txt', 'text/plain', '/Clients/Iso');
$secFile = $app->files()->save(tap_tmp('the vault codes'), 'codes.txt', 'text/plain', '/Vault');
$plain = $app->files()->save(tap_tmp('a plain note'), 'plain.txt', 'text/plain', '/Inbox');
$app->setSpace(0); Privacy::begin();
check('shellspace: the shell tools are offered', in_array('shell_run', $app->tools->names(), true));
$r = $app->tools->run('shell_run', ['command' => 'true', 'files' => [$confFile]]);
check('shellspace: a personal subscription cannot copy a Confidential file into the sandbox', str_starts_with($r, 'error') && str_contains($r, 'Confidential') && !is_file($app->shell()->workDir() . '/in/contract.txt'), $r);
$r = $app->tools->run('shell_run', ['command' => 'true', 'files' => [$secFile]]);
check('shellspace: a Secret space\'s file is not even found from elsewhere', str_starts_with($r, 'error') && str_contains($r, "no file $secFile") && !str_contains($r, 'vault'), $r);
$r = $app->tools->run('shell_run', ['command' => 'true', 'files' => [$plain]]);
check('shellspace: a file it may read is copied in', str_contains($r, 'Copied into /work: in/plain.txt') && is_file($app->shell()->workDir() . '/in/plain.txt'), $r);
// a business account may read the Confidential file: then the work is Confidential, and nothing goes down a level
$app->cfg->provider = 'anthropic'; $app->cfg->anthropicKey = 'k'; Privacy::begin();
$r = $app->tools->run('shell_run', ['command' => 'cp in/contract.txt out.txt', 'files' => [$confFile]]);
check('shellspace: copying a Confidential file in makes the work Confidential', str_contains($r, 'in/contract.txt') && $app->privacy()->effective() === 'confidential', $r);
$r = $app->tools->run('shell_save', ['path' => 'out.txt', 'folder' => '/Inbox']);
check('shellspace: what it made is not saved in a less protected folder', str_contains($r, 'not done') && !$app->store->q("SELECT id FROM files WHERE name = 'out.txt'")->fetchColumn(), $r);
$r = $app->tools->run('shell_save', ['path' => 'out.txt', 'folder' => '/Clients/Iso']);
check('shellspace: ... but in the folder of its level', str_starts_with($r, 'saved /Clients/Iso/out.txt'), $r);
Privacy::begin();
$general = $app->shell()->workDir(); $app->setSpace((int)$conf['id']); $own = $app->shell()->workDir(); $app->setSpace(0);
check('shellspace: each space has its own /work', $general !== $own && is_dir($own));
Privacy::trustAgain(); Privacy::$taint = 0; Shell::$binaryOverride = null;
foreach ($keep as $k => $v) $v === false ? putenv($k) : putenv("$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
