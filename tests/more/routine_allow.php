<?php
declare(strict_types=1);
// 1.35.48: once a routine has read the web, its writes wait for you (audit S5); "Always allow for the routine" lets it
// make that one kind of change again without asking (that note, new tasks), and nothing else.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Privacy, Routines};

$d = test_dir('routine-allow');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$waits = fn(string $r) => str_contains($r, "Waiting for the user's approval");
$idOf = fn(string $r) => preg_match('/request #(\d+)/', $r, $m) ? (int)$m[1] : 0;

$watch = $app->store->addTask('Veille sécurité', 'Check the Debian security tracker and update the note.', '0 11 * * *', time() + 3600);
$other = $app->store->addTask('Other routine', 'Something else.', '0 9 * * *', time() + 3600);
$note = (int)$app->library()->saveNote('/Notes', 'Veille.md', "# Veille\n")['id'];
$second = (int)$app->library()->saveNote('/Notes', 'Plan.md', "# Plan\n")['id'];

// a run of the routine, after reading a web page
$app->routine = $watch; $T = $app->tools->forAnswer();
Privacy::begin(); Privacy::$untrusted = true;
$r1 = $T->run('kb_save_note', ['id' => $note, 'name' => 'Veille.md', 'content' => "# Veille\nrun 1"]);
$r2 = $T->run('board_add', ['title' => 'apache2: DSA-6620-1']);
$r3 = $T->run('memory_append', ['file' => 'MEMORY.md', 'text' => '- planted']);
check('routine allow: before any grant, the routine\'s writes after reading the web wait', $waits($r1) && $waits($r2) && $waits($r3), "$r1 | $r2");
$pend = array_column($app->approvals()->pending(), null, 'id');
check('routine allow: the requests remember the routine, except memory (it cannot be granted)', (int)$pend[$idOf($r1)]['routine'] === $watch && (int)$pend[$idOf($r2)]['routine'] === $watch
    && (int)$pend[$idOf($r3)]['routine'] === 0);
$app->routine = 0; Privacy::trustAgain();
$out = $app->approvals()->always($idOf($r1));
$app->approvals()->always($idOf($r2));
check('routine allow: "Always allow" runs it and records the grant on the routine', str_starts_with($out, 'Done') && str_contains((string)file_get_contents($app->files()->get($note)["path"]), 'run 1')
    && array_column($app->routines()->grants($watch), 'key') === ["kb_save_note:$note", 'board_add'] && $app->routines()->grants($other) === [], json_encode($app->routines()->grants($watch)));
$app->approvals()->decide($idOf($r3), false);

// the next run: that note and new tasks go through; anything else still asks
$app->routine = $watch; $T = $app->tools->forAnswer();
Privacy::begin(); Privacy::$untrusted = true;
$a = $T->run('kb_save_note', ['id' => $note, 'name' => 'Veille.md', 'content' => "# Veille\nrun 2"]);
$b = $T->run('board_add', ['title' => 'nginx: watch']);
check('routine allow: the next run updates its note and adds a task without asking', !$waits($a) && str_contains($a, 'saved') && !$waits($b)
    && str_contains((string)file_get_contents($app->files()->get($note)["path"]), 'run 2'), "$a | $b");
check('routine allow: another note, a new note, memory still ask', $waits($T->run('kb_save_note', ['id' => $second, 'content' => 'x']))
    && $waits($T->run('kb_save_note', ['name' => 'new.md', 'content' => 'x'])) && $waits($T->run('memory_append', ['file' => 'MEMORY.md', 'text' => '- planted'])));
$app->routines()->grant($watch, 'kb_save_note', ['name' => 'new.md']); // new notes too
$T = $app->tools->forAnswer();
check('routine allow: a "new notes" grant does not cover a note named by a malformed id', $waits($T->run('kb_save_note', ['id' => "{$second}x", 'content' => 'planted']))
    && !str_contains((string)file_get_contents($app->files()->get($second)['path']), 'planted'));
$app->routines()->revoke($watch, 'kb_save_note');
$app->routine = $other; $T = $app->tools->forAnswer();
check('routine allow: another routine (or a chat) is not covered', $waits($T->run('board_add', ['title' => 'x'])));
$app->routine = 0; $T = $app->tools->forAnswer();
check('routine allow: ... nor a conversation', $waits($T->run('kb_save_note', ['id' => $note, 'content' => 'x'])));
Privacy::trustAgain();

// the Claude Code tool process learns which routine runs
$app->routine = $watch;
check('routine allow: the tool process of Claude Code is told the routine', ($app->tools->env()['TALIXIO_ROUTINE'] ?? '') === (string)$watch);
$app->routine = 0;

// taken back from the routine's card
check('routine allow: a grant can be removed, then it asks again', $app->routines()->revoke($watch, 'board_add') && !$app->routines()->revoke($watch, 'board_add')
    && array_column($app->routines()->grants($watch), 'key') === ["kb_save_note:$note"]);
$app->routine = $watch; $T = $app->tools->forAnswer(); Privacy::begin(); Privacy::$untrusted = true;
check('routine allow: ... the removed one asks again', $waits($T->run('board_add', ['title' => 'y'])));
$app->routine = 0; Privacy::trustAgain();
check('routine allow: only notes, documents and tasks can be granted', Routines::grantable('kb_save_note') && Routines::grantable('board_add')
    && !Routines::grantable('memory_append') && !Routines::grantable('mail_send') && !Routines::grantable('shell_run') && !Routines::grantable('skill_save'));

foreach ($saved as $k => $v) $v === false ? putenv($k) : putenv("$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
