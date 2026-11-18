<?php
declare(strict_types=1);
// 1.41.3: ways content of a Confidential or Secret space could still leave, found by a review of the levels: a usage routed
// to another account, transcription before the space is known, notifications after reading up, the e-mail channel.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Http, Privacy, PrivacyBlocked};

$d = test_dir('confidential');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$usage = fn(callable $on) => $on(['model' => 'claude-sonnet-x', 'in' => 10, 'out' => 2, 'cache_read' => 0, 'cache_write' => 0, 'cost' => null]);
$fake = new FakeModel(); $fake->script = function ($last, $tools, $on) use ($usage) { $usage($on); return 'answered'; };
$app->providerOverride = $fake;
$vault = $app->spaces()->save(['name' => 'Vault', 'folder' => '/Vault', 'privacy' => 'secret']);
$acme = $app->spaces()->save(['name' => 'Acme', 'folder' => '/Clients/Acme', 'privacy' => 'confidential']);

// ---- C1: the model a usage is routed to is the one that reads, not the space's main one
// the main model is on this server (echo counts as local): a Secret space may chat; routed to an API outside, it may not
$r = $app->agent()->chat('web', 'hello', 'standard', [], null, (int)$vault['id']);
check('confidential: with the main model on this server, a Secret space chats', $r === 'answered' && count($fake->calls) === 1, $r);
$oa = $app->accounts()->save(['name' => 'Outside', 'kind' => 'openai', 'key' => 'sk-test', 'models' => ['fast' => 'f', 'standard' => 's', 'deep' => 'd']]);
$app->routing()->set(null, 'standard', $oa['id']);
$r = $app->agent()->chat('web', 'the vault code is 4471', 'standard', [], null, (int)$vault['id']);
check('confidential: a usage routed to a model outside is refused in a Secret space, the model never called', str_contains($r, 'Secret space') && count($fake->calls) === 1, $r);
$r = $app->agent()->chat('web', 'hello', 'standard', [], null, (int)$acme['id']);
check('confidential: a business-terms API still serves a Confidential space', $r === 'answered' && count($fake->calls) === 2, $r);
$app->routing()->set(null, 'standard', null);

// ---- C2: a voice note is transcribed by the rules of the space it is said in, not of the Inbox it waits in
$seen = []; $hookWas = Http::$hook;
Http::$hook = function (string $url, int $bytes) use (&$seen, $hookWas) { $hookWas($url, $bytes); $seen[] = $url; throw new \RuntimeException('offline in tests'); };
$app->cfg->transcribe = 'openai'; $app->cfg->openaiKey = 'sk-test';
$wav = "$d/note.wav"; file_put_contents($wav, 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 0));
$fid = $app->files()->save($wav, 'note.wav', 'audio/wav', Talixio\Library::INBOX);
$err = '';
try { $app->agent()->audio('signal', $fid, '', null, (int)$vault['id']); } catch (\Throwable $e) { $err = $e->getMessage(); }
$out = array_filter($seen, fn($u) => str_contains($u, 'openai.com'));
check('confidential: a voice note said in a Secret space is not sent to a transcription service outside', $out === [], json_encode([$seen, $err]));
Http::$hook = $hookWas;

// ---- C5: a routine's or a job's notice keeps to the level its work reached, not only its space's
$plan = $app->files()->save(tap_tmp('Acme plan: the merger closes on 4 March for 12 M€.'), 'plan.txt', 'text/plain', '/Clients/Acme');
$fake->script = function ($last, $tools, $on) use ($usage, $plan) { $usage($on); $tools->run('file_read', ['id' => $plan]); return 'Acme: the merger closes on 4 March for 12 M€.'; };
$reply = $app->agent()->chat('schedule', 'Summarise the Acme plan', 'standard', [], null, 0);
check('confidential: a turn in General that read a Confidential file is known to have reached that level', Talixio\Agent::$lastLevel === 'confidential', Talixio\Agent::$lastLevel . " / $reply");
$sent = [];
Http::$hook = function (string $url, int $bytes) use (&$sent) { $sent[] = $bytes; throw new \RuntimeException('offline in tests'); };
$app->cfg->notify = ['ntfy']; $app->cfg->ntfyUrl = 'https://ntfy.example/t'; $app->cfg->notifyNeutral = true;
$app->notifier()->notify('Acme summary', $reply, 0);
$app->cfg->notifyNeutral = false;
$app->notifier()->notify('Acme summary', $reply, 0, Talixio\Agent::$lastLevel);
check('confidential: its notice says only that there is a new message, as for a Confidential space', count($sent) === 2 && $sent[0] === $sent[1], json_encode($sent));
$app->notifier()->notify('Acme summary', $reply, 0);
check('confidential: a notice at the Internal level still carries its text', count($sent) === 3 && $sent[2] > $sent[0], json_encode($sent));
Http::$hook = $hookWas; $app->cfg->notify = [];

// ---- C6: the e-mail channel above Internal, any chat app in a Secret space
$fake->script = function ($last, $tools, $on) use ($usage) { $usage($on); return 'answered'; };
$r = $app->agent()->chat('email', '/space Acme');
check('confidential: e-mail cannot switch to a Confidential space', str_contains($r, 'e-mail goes through mail servers') && $app->spaces()->forChannel('email') === 0, $r);
$r = $app->agent()->chat('signal', '/space Vault');
check('confidential: Signal cannot switch to a Secret space', str_contains($r, 'Secret space') && $app->spaces()->forChannel('signal') === 0, $r);
$r = $app->agent()->chat('signal', '/space Acme');
check('confidential: Signal may still switch to a Confidential space', $app->spaces()->forChannel('signal') === (int)$acme['id'], $r);
$n = count($fake->calls);
$app->spaces()->setForChannel('email', (int)$acme['id']); // set before this version
$r = $app->agent()->chat('email', 'what does the Acme plan say?');
check('confidential: e-mail already in a Confidential space is refused, the model never called', str_contains($r, 'e-mail goes through mail servers') && count($fake->calls) === $n, $r);
$r = $app->agent()->chat('signal', 'the vault code?', 'standard', [], null, (int)$vault['id']);
check('confidential: Signal in a Secret space is refused, the model never called', str_contains($r, 'Secret space') && count($fake->calls) === $n, $r);
$app->spaces()->setForChannel('email', 0); $app->spaces()->setForChannel('signal', 0);

// ---- C7: a strict space always has a folder, so what is sent in it does not stay in the Inbox (read at the Internal level)
$hush = $app->spaces()->save(['name' => 'Hush', 'privacy' => 'confidential']);
check('confidential: a Confidential space made without a folder gets its own', $hush['folder'] === '/Hush' && $app->privacy()->folderLevel('/Hush/a.txt') === 'confidential', json_encode($hush['folder']));
$app->store->q("UPDATE spaces SET folder = '' WHERE id = ?", [$hush['id']]); // one made before this version
$att = $app->files()->save(tap_tmp('the board minutes'), 'minutes.txt', 'text/plain', Talixio\Library::INBOX);
$app->agent()->chat('web', 'file this', 'standard', [$att], null, (int)$hush['id']);
$af = $app->files()->get($att)['folder'];
check('confidential: an attachment sent in an older folderless one is filed at its level, not left in the Inbox', $af === '/Hush' && $app->privacy()->folderLevel($af) === 'confidential', $af);
$plain = $app->spaces()->save(['name' => 'Garden']);
check('confidential: an Internal space may still have no folder', $plain['folder'] === '');

// ---- C4: answering another person's assistant, a Confidential folder nested in the shared space's folder stays closed
$team = $app->spaces()->save(['name' => 'Team', 'folder' => '/Team']);
$deals = $app->spaces()->save(['name' => 'Deals', 'folder' => '/Team/Deals', 'privacy' => 'confidential']);
$pv = $app->privacy();
$lvl = $pv->folderLevel('/Team/Deals/q4.txt');
check('confidential: a question from another assistant reads the shared folder', Privacy::confined('/Team', fn() => $pv->mayRead('internal', null, '/Team/notes.txt')));
check('confidential: ...but not a Confidential folder nested in it', $lvl === 'confidential' && !Privacy::confined('/Team', fn() => $pv->mayRead($lvl, null, '/Team/Deals/q4.txt')));
check('confidential: outside such a question, the space\'s own work still reads it', $pv->mayRead($lvl, null, '/Team/Deals/q4.txt'));
// C3 (an instruction to the cloud fleet is grounded in Internal knowledge only) went with the fleet relay (1.46.1).

foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
