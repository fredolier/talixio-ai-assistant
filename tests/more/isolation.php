<?php
declare(strict_types=1);
// Space isolation: what a model may read, where results may be written, which spaces the work may act on.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Privacy};

$d = test_dir('isolation');
$keep = [];
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'claude-code', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_SEARCH' => 'auto'] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv();
$pv = $app->privacy();
$mk = function (string $text): string { $t = tempnam(sys_get_temp_dir(), 'iso'); file_put_contents($t, $text); return $t; };
$conf = $app->spaces()->save(['name' => 'Client Iso', 'folder' => '/Clients/Iso', 'privacy' => 'confidential']);
$sec = $app->spaces()->save(['name' => 'Vault Iso', 'folder' => '/Vault', 'privacy' => 'secret']);
$cid = (int)$conf['id']; $sid = (int)$sec['id'];
$confFile = $app->files()->save($mk('the Iso contract terms'), 'contract.txt', 'text/plain', '/Clients/Iso');
$app->setSpace(0);

// ---- reading: a personal subscription in General does not read a Confidential space's files
check('isolation: a personal subscription may not read Confidential content', !$pv->mayRead('confidential', null, '/Clients/Iso') && $pv->mayRead('internal'));
check('isolation: the person searching may (it is their own data)', Privacy::forPerson(fn() => $pv->mayRead('confidential', null, '/Clients/Iso')) && !Privacy::$person);
check('isolation: a Secret space stays hidden even from the person elsewhere', !Privacy::forPerson(fn() => $pv->mayRead('secret', $sid)));
check('isolation: file_read says why, without the content', str_contains($r = $app->tools->run('file_read', ['id' => $confFile]), 'Confidential space that this model may not read') && !str_contains($r, 'contract terms'), $r);
check('isolation: kb_list hides the folder', !str_contains($app->tools->run('kb_list', []), '/Clients/Iso') && str_contains($app->tools->run('kb_list', ['folder' => '/Clients/Iso']), 'may not read'));
$app->cfg->provider = 'anthropic';
check('isolation: a business account reads it', str_contains($app->tools->run('file_read', ['id' => $confFile]), 'contract terms'));
$app->cfg->provider = 'claude-code';

// ---- writing: nothing goes down a level
check('isolation: writeDown refuses a less protected folder', $pv->writeDown('/Inbox', 'confidential') !== null && $pv->writeDown('/Clients/Iso/Notes', 'confidential') === null && $pv->writeDown('/Inbox', 'internal') === null);
check('isolation: writeDown refuses another space\'s Secret folder', $pv->writeDown('/Vault', 'internal') !== null);
$app->setSpace($cid); $app->cfg->provider = 'anthropic'; Privacy::begin();
$r = $app->tools->run('kb_save_note', ['folder' => '/Inbox', 'name' => 'leak.md', 'content' => 'terms']);
check('isolation: a Confidential space does not save its notes in the Inbox', str_contains($r, 'not done') && !$app->store->q("SELECT id FROM files WHERE name = 'leak.md'")->fetchColumn(), $r);
$r = $app->tools->run('kb_save_note', ['folder' => '/Clients/Iso/Notes', 'name' => 'ok.md', 'content' => 'terms']);
check('isolation: ... but in its own folder', (bool)$app->store->q("SELECT id FROM files WHERE name = 'ok.md'")->fetchColumn(), $r);
$r = $app->tools->run('kb_move', ['id' => $confFile, 'folder' => '/Inbox']);
check('isolation: kb_move does not take a file down a level', str_contains($r, 'not done') && $app->files()->get($confFile)['folder'] === '/Clients/Iso', $r);
check('isolation: shared memory, contacts and skills refuse Confidential work', str_contains($app->tools->run('memory_append', ['file' => 'MEMORY.md', 'text' => 'x']), 'not saved')
    && str_contains($app->tools->run('contact_save', ['name' => 'Dupont', 'notes' => 'x']), 'not saved'));
Privacy::end(); Privacy::$taint = 0;
// after reading a Confidential file in General (business account), the same holds
$app->setSpace(0); $app->cfg->provider = 'anthropic'; Privacy::begin();
$app->tools->run('file_read', ['id' => $confFile, 'offset' => 1]); // not the same call as above (twice is refused)
check('isolation: reading Confidential content in General makes the work Confidential', $pv->effective() === 'confidential');
check('isolation: ... so it is not copied into shared memory', str_contains($app->tools->run('memory_append', ['file' => 'MEMORY.md', 'text' => 'Iso terms'], ), 'not saved'));
check('isolation: ... and the provider\'s own web search is off', !$app->tools->allowsWeb());
Privacy::end(); Privacy::$taint = 0;
check('isolation: the provider\'s web search is back for Internal work', $app->tools->allowsWeb());
$app->cfg->provider = 'claude-code';

// ---- audit S11: the board of other spaces, from General
$bConf = $app->board()->create(['title' => 'Iso merger due diligence', 'body' => 'confidential terms'], $cid, 'you');
$bSec = $app->board()->create(['title' => 'Vault codes rotation', 'body' => 'secret steps'], $sid, 'you');
$bGen = $app->board()->create(['title' => 'Buy printer paper'], 0, 'you');
$app->setSpace(0); Privacy::begin(); $app->cfg->provider = 'claude-code';
$all = $app->tools->run('board_list', ['all_spaces' => true]);
check('isolation: from General, all_spaces hides the tasks of spaces this model may not read', str_contains($all, 'Buy printer paper') && !str_contains($all, 'Iso merger') && !str_contains($all, 'Vault codes'), $all);
check('isolation: board_get of such a task finds nothing', str_starts_with($app->tools->run('board_get', ['code' => $bConf['code']]), 'error: no task') && str_starts_with($app->tools->run('board_get', ['code' => $bSec['code']]), 'error: no task'));
$app->cfg->provider = 'anthropic'; Privacy::begin();
$all = $app->tools->run('board_list', ['all_spaces' => true, 'query' => '']);
check('isolation: a business account sees a Confidential space\'s tasks, and the work becomes Confidential', str_contains($all, 'Iso merger') && $pv->effective() === 'confidential', $all);
check('isolation: ... never a Secret space\'s', !str_contains($all, 'Vault codes') && str_starts_with($app->tools->run('board_get', ['code' => $bSec['code']]), 'error: no task'));
Privacy::end(); Privacy::$taint = 0; $app->cfg->provider = 'claude-code';

// ---- acting on other spaces: jobs and routines of a space the model may not read are not seen
Privacy::trustAgain(); // a new answer: what an earlier section read does not carry over (the untrusted guard, audit S5)
$app->store->addTask('Iso weekly', 'check Iso', '0 8 * * 1', time() + 3600, 'standard', $cid);
$tid = (int)$app->store->db->lastInsertId();
$app->store->addTask('General daily', 'hello', '0 8 * * *', time() + 3600);
$app->setSpace(0);
$lt = $app->tools->run('list_tasks', []);
check('isolation: list_tasks hides routines of a space this model may not read', !str_contains($lt, 'Iso weekly') && str_contains($lt, 'General daily'), $lt);
check('isolation: cancel_task cannot delete them', $app->tools->run('cancel_task', ['id' => $tid]) === 'error: no such task' && $app->store->task($tid) !== null);
check('isolation: routine_feedback cannot rate them', str_contains($app->tools->run('routine_feedback', ['routine' => 'Iso weekly', 'item' => 'x', 'vote' => 'up']), 'no such routine'));
$app->store->q("INSERT INTO jobs(title, instructions, tier, status, created, space, max_cost_usd) VALUES('Iso research', 'x', 'standard', 'queued', ?, ?, 1)", [time(), $cid]);
$jid = (int)$app->store->db->lastInsertId();
check('isolation: list_jobs all_spaces hides their jobs', !str_contains($app->tools->run('list_jobs', ['all_spaces' => true]), 'Iso research'));
check('isolation: cancel_job and continue_job refuse them', str_starts_with($app->tools->run('cancel_job', ['id' => $jid]), 'error')
    && $app->tools->run('continue_job', ['id' => $jid]) === 'error: no such job' && $app->store->job($jid)['status'] === 'queued');
$sl = $app->tools->run('spaces', ['action' => 'list']);
check('isolation: the spaces list does not show a Secret space\'s folder', str_contains($sl, 'Vault Iso') && !str_contains($sl, '/Vault'), $sl);
$app->setSpace($cid); $app->cfg->provider = 'anthropic';
check('isolation: inside its space they are there', str_contains($app->tools->run('list_tasks', []), 'Iso weekly'));
$app->setSpace(0); $app->cfg->provider = 'claude-code';

// ---- the Mind keeps a walled space's observations in their own group
$app->mind()->addObservation('pattern', 'general fact', 2);
$app->mind()->addObservation('pattern', 'Iso fact', 2, $cid);
$app->mind()->addObservation('pattern', 'Vault fact', 2, $sid);
$g = $app->mind()->pendingGroups();
check('isolation: Mind consolidates each Confidential or Secret space apart', ($g[0] ?? 0) === 1 && ($g[$cid] ?? 0) === 1 && ($g[$sid] ?? 0) === 1, json_encode($g));

foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
