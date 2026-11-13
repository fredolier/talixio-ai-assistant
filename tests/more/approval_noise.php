<?php
declare(strict_types=1);
// 1.35.51: approvals after reading the web filled the chat: each request showed the tool's whole arguments (a 3,000-character
// note), and each result was a message of its own. A request is one line now (the arguments folded under it), and results
// decided one after the other go into one message.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Privacy, Tools};

$d = test_dir('approval-noise');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$idOf = fn(string $r) => preg_match('/request #(\d+)/', $r, $m) ? (int)$m[1] : 0;
$script = "#!/usr/bin/env bash\n" . str_repeat("echo \"check the kernel version\"\n", 120);
$notes = array_map(fn($n) => (int)$app->library()->saveNote('/ACR/plugins', "CVE-$n.sh", "old\n")['id'], [1, 2, 3]);

$T = $app->tools->forAnswer();
Privacy::begin(); Privacy::$untrusted = true;
$ids = array_map(fn($i) => $idOf($T->run('kb_save_note', ['id' => $notes[$i], 'name' => 'CVE-' . ($i + 1) . '.sh', 'folder' => '/ACR/plugins', 'content' => $script])), [0, 1, 2]);
Privacy::trustAgain();
$a = $app->approvals()->get($ids[0]);
check('approval noise: the request is one short line, the content replaced by its length', !str_contains($a['summary'], 'check the kernel')
    && str_starts_with($a['summary'], "Run kb_save_note: id {$notes[0]}, name \"CVE-1.sh\", folder \"/ACR/plugins\", content (" . number_format(mb_strlen($script)) . ' characters)')
    && mb_strlen(explode("\n", $a['summary'])[0]) < 140, $a['summary']);
check('approval noise: the arguments are still all there, for the folded details', json_decode($a['args'], true)['content'] === $script);
check('approval noise: short values, numbers and lists are shown as they are', Tools::brief('board_add', ['title' => 'apache2', 'priority' => 2, 'tags' => ['a', 'b'], 'done' => false])
    === 'Run board_add: title "apache2", priority 2, tags "["a","b"]", done "no"' && Tools::brief('x', []) === 'Run x', Tools::brief('board_add', ['title' => 'apache2', 'priority' => 2, 'tags' => ['a', 'b'], 'done' => false]));

$before = $app->store->maxMessageId();
foreach ($ids as $id) $app->approvals()->decide($id, true);
$msgs = $app->store->messagesAfter($before);
$content = $msgs[0]['content'] ?? '';
check('approval noise: three approvals one after the other make one message', count($msgs) === 1 && str_starts_with($content, "Done: 3 approved actions\n\n")
    && substr_count($content, 'Done: Run kb_save_note') === 3, json_encode(array_column($msgs, 'content')));
$meta = is_array($msgs[0]["meta"]) ? $msgs[0]["meta"] : json_decode((string)$msgs[0]["meta"], true);
check("approval noise: it lists the three and keeps their results", ($meta['approvals'] ?? []) === $ids && !isset($meta['trust'])
    && count($meta['replaces'] ?? []) === 1 && $meta['replaces'][0] < (int)$msgs[0]['id'] && str_contains($content, 'CVE-3.sh'), json_encode($meta) . ' ' . json_encode($ids));
check('approval noise: the notes were all written', !array_filter($notes, fn($n) => !str_contains((string)file_get_contents($app->files()->get($n)['path']), 'check the kernel')));

// something said in between starts a new message; so does a rejected one not
$T = $app->tools->forAnswer(); Privacy::begin(); Privacy::$untrusted = true;
$n4 = $idOf($T->run('board_add', ['title' => 'apache2: DSA-6620-1']));
$n5 = $idOf($T->run('board_add', ['title' => 'nginx: watch']));
Privacy::trustAgain();
$app->store->addMessage('user', 'web', 'merci');
$mark = $app->store->maxMessageId();
$app->approvals()->decide($n4, true);
$app->approvals()->decide($n5, true);
$after = $app->store->messagesAfter($mark);
check('approval noise: after a message of yours, the next results start a new message', count($after) === 1 && str_starts_with($after[0]['content'], 'Done: 2 approved actions')
    && count(array_filter($app->store->messagesAfter($before), fn($m) => $m['channel'] === 'approval')) === 2);
// what a result read (a page fetched on approval) stays marked when later results join its message
$last = $app->store->q("SELECT id, meta FROM messages WHERE channel = 'approval' ORDER BY id DESC LIMIT 1")->fetch();
$app->store->q('UPDATE messages SET meta = ? WHERE id = ?', [json_encode(json_decode($last['meta'], true) + ['trust' => ['untrusted' => true, 'private' => false, 'links' => ['https://example.org/']]]), $last['id']]);
$T = $app->tools->forAnswer(); Privacy::begin(); Privacy::$untrusted = true;
$n6 = $idOf($T->run('board_add', ['title' => 'mariadb'])); Privacy::trustAgain();
$app->approvals()->decide($n6, true);
$m6 = json_decode((string)$app->store->q("SELECT meta FROM messages WHERE channel = 'approval' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
check('approval noise: the gathered message stays marked as holding content written by others', !empty($m6['trust']['untrusted']) && in_array('https://example.org/', $m6['trust']['links'] ?? [], true)
    && count($m6['approvals'] ?? []) === 3, json_encode($m6));
check('approval noise: one result alone reads as before', (function () use ($app) {
    $T = $app->tools->forAnswer(); Privacy::begin(); Privacy::$untrusted = true;
    $r = $T->run('board_add', ['title' => 'alone']); Privacy::trustAgain();
    $app->store->addMessage('user', 'web', 'ok'); $mk = $app->store->maxMessageId();
    $app->approvals()->decide((int)(preg_match('/request #(\d+)/', $r, $m) ? $m[1] : 0), true);
    $one = $app->store->messagesAfter($mk);
    return count($one) === 1 && str_starts_with($one[0]['content'], 'Done: Run board_add: title "alone"' . "\n\n");
})());

// a routine's results gather under its name, apart from the conversation's
$watch = $app->store->addTask('Veille sécurité', 'Check the tracker.', '0 11 * * *', time() + 3600);
$app->store->addMessage('user', 'web', 'next'); $mk = $app->store->maxMessageId();
$app->routine = $watch; $T = $app->tools->forAnswer(); Privacy::begin(); Privacy::$untrusted = true;
$r1 = $idOf($T->run('board_add', ['title' => 'r1'])); $r2 = $idOf($T->run('board_add', ['title' => 'r2']));
$app->routine = 0; $T = $app->tools->forAnswer();
$c1 = $idOf($T->run('board_add', ['title' => 'chat'])); Privacy::trustAgain();
foreach ([$r1, $r2, $c1] as $id) $app->approvals()->decide($id, true);
$rm = $app->store->messagesAfter($mk);
check('approval noise: a routine\'s results gather under its name, apart from the conversation\'s', count($rm) === 2
    && str_starts_with($rm[0]['content'], 'Done: 2 approved actions (routine "Veille sécurité")') && str_starts_with($rm[1]['content'], 'Done: Run board_add: title "chat"'), json_encode(array_column($rm, 'content')));

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
