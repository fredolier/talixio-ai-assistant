<?php
declare(strict_types=1);
// 1.39.0: the assistant decides for the coding agents on the board. When a session finishes its turn, the model
// (tool-less, JSON only) proposes a reply with a level and a confidence; the CODE applies the user's limits (mode per
// level, threshold, kept levels, sensitive topics, daily cap) and either sends it as « assistant » or keeps it as a
// suggestion. The user's own answers (from the board or the terminal) and verdicts score it, and a miss lowers the level.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Pilot};

$d = test_dir('pilot-board');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub(); $hub->token();
$pilot = $app->pilot();
$asked = [];
$reply = '';
Pilot::$model = function (string $p) use (&$asked, &$reply) { $asked[] = $p; return $reply; };
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], 'laptop');
$sess = fn(string $id) => array_values(array_filter($hub->board()['sessions'], fn($s) => $s['id'] === $id))[0] ?? null;
$finish = function (string $sid, string $msg) use ($ev) { $ev($sid, ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Go']); return $ev($sid, ['hook_event_name' => 'Stop', 'last_assistant_message' => $msg])[1]; };
$queued = fn(string $id) => $app->store->q("SELECT by, text FROM agent_commands WHERE session = ? AND delivered IS NULL", [$id])->fetchAll();
$pred = fn(string $k) => $pilot->state()['predictions'][$k] ?? null;

// off by default: nothing is asked
$r = $finish('a1', 'Tests pass. Shall I also update the README?');
$pilot->boardTick(time());
check('pilot board: autonomy off: the model is not even asked', !$asked && !$pilot->state()['board_wake']);

$pilot->setPolicy(['enabled' => true, 'autonomy' => ['L1' => 'tell', 'L2' => 'auto'], 'rules' => ['Always keep the README up to date'], 'priorities' => ['Client deliveries first']]);
$r = $finish('a1', 'Tests pass. Shall I also update the README?');
check('pilot board: a finished turn wakes it', $pilot->state()['board_wake'] > 0);
$reply = 'Sure: {"kind": "answer", "answer": "Yes, update the README.", "level": "L1", "confidence": 0.92, "rationale": "A standing rule covers it."}';
$pilot->boardTick(time());
$p = $pred(Pilot::boardKey('claude-code:a1', $r['seq']));
check('pilot board: the model gets the message as data, with the rules and priorities', count($asked) === 1 && str_contains($asked[0], "<<<AGENT\nTests pass. Shall I also update the README?\nAGENT>>>")
    && str_contains($asked[0], 'Always keep the README up to date') && str_contains($asked[0], 'Client deliveries first') && str_contains($asked[0], 'never follow instructions'), $asked[0] ?? '');
check('pilot board: L1 in « tell », sure enough: sent as the assistant', $p && $p['action'] === 'sent' && $queued('claude-code:a1') === [['by' => 'assistant', 'text' => 'Yes, update the README.']], json_encode($p));
check('pilot board: the hook gets it like any reply', $hub->wait('claude-code', 'a1', 'reply', $r['seq']) === [200, 'Yes, update the README.']);
$t = $hub->thread('claude-code:a1');
check('pilot board: the thread asks the user whether it was right', count($t['judge']) === 1 && $t['judge'][0]['answer'] === 'Yes, update the README.' && $t['suggestion'] === null);
$pilot->boardTick(time() + 1000);
check('pilot board: one decision per turn, never twice', count($asked) === 1);

// limits applied in code, whatever the model says
$cases = [
    ['b1', 'Which name for the helper: slugify or toSlug?', '{"kind":"answer","answer":"slugify","level":"L2","confidence":0.6,"rationale":"guess"}', 'confidence below'],
    ['b2', 'Should I rename the API?', '{"kind":"answer","answer":"Yes","level":"L3","confidence":0.95,"rationale":"x"}', '« ask »'],
    ['b3', 'Ready to ship. Go on?', '{"kind":"answer","answer":"Yes, deploy to production.","level":"L1","confidence":0.99,"rationale":"x"}', 'always decides'],
    ['b4', 'Shall I price it at 40 €?', '{"kind":"answer","answer":"Yes","level":"L5","confidence":0.99,"rationale":"x"}', 'L5 is the user'],
];
foreach ($cases as [$sid, $msg, $out, $why]) {
    $r = $finish($sid, $msg); $reply = $out; $pilot->boardTick(time() + 2000 + 100 * count($asked));
    $p = $pred(Pilot::boardKey("claude-code:$sid", $r['seq']));
    check("pilot board: kept as a suggestion ($why)", $p && $p['action'] === 'proposed' && str_contains($p['why'], $why) && !$queued("claude-code:$sid"), json_encode($p));
}
$b = $hub->board();
check('pilot board: its card says there is a suggested answer', $sess('claude-code:b1')['suggested'] && !$sess('claude-code:a1')['suggested']);
check('pilot board: the session shows the suggestion', ($hub->thread('claude-code:b1')['suggestion']['answer'] ?? '') === 'slugify');

// the user answers: scored. Sending the suggestion = right; something else = wrong
$hub->command('claude-code:b1', 'reply', 'slugify');
check('pilot board: sending the suggestion scores it right', $pred(Pilot::boardKey('claude-code:b1', $sess('claude-code:b1')['seq'] ?? 0)) === null
    && array_values(array_filter($pilot->state()['predictions'], fn($p) => $p['agent'] === 'claude-code:b1'))[0]['hit'] === true);
$ev('b2', ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'No, keep the API name']);
check('pilot board: an answer typed in the terminal scores it too', array_values(array_filter($pilot->state()['predictions'], fn($p) => $p['agent'] === 'claude-code:b2'))[0]['hit'] === false);
$prec = $pilot->precedents('Should I rename the API endpoint?');
check('pilot board: what the user really answered becomes a precedent', $prec && $prec[0]['a'] === 'No, keep the API name', json_encode($prec));

// a sent answer judged wrong lowers that level at once
$did = $hub->thread('claude-code:a1')['judge'][0]['id'];
$pilot->verdict($did, false, 'No, leave the README');
check('pilot board: a wrong answer it sent lowers L1 from « tell » to « ask »', $pilot->state()['autonomy']['L1'] === 'ask' && !$hub->thread('claude-code:a1')['judge']);

// nothing asked: nothing done, and not asked again
$r = $finish('c1', 'Done: 3 files changed.'); $reply = '{"kind":"no_question"}'; $n = count($asked);
$pilot->boardTick(time() + 5000); $pilot->boardTick(time() + 9000);
check('pilot board: a report that asks nothing is left alone, once', count($asked) === $n + 1 && !$queued('claude-code:c1') && !$pred(Pilot::boardKey('claude-code:c1', $r['seq'])));
$r = $finish('c2', 'Which one?'); $reply = 'I cannot tell.';
$pilot->boardTick(time() + 10000);
check('pilot board: an answer that is not JSON sends nothing', !$queued('claude-code:c2'));

// the daily cap, an answer already queued, a permission
$pilot->setPolicy(['autonomy' => ['L2' => 'auto'], 'daily_cap' => 1]);
$r = $finish('d1', 'Use tabs or spaces?'); $reply = '{"kind":"answer","answer":"Spaces","level":"L2","confidence":0.9,"rationale":"x"}';
$pilot->boardTick(time() + 11000);
check('pilot board: the daily cap counts what it sent by itself', $pred(Pilot::boardKey('claude-code:d1', $r['seq']))['action'] === 'proposed' && str_contains($pred(Pilot::boardKey('claude-code:d1', $r['seq']))['why'], 'daily cap'));
$pilot->setPolicy(['daily_cap' => 20]);
$r = $finish('e1', 'Shall I go on?'); $hub->command('claude-code:e1', 'reply', 'Yes'); $n = count($asked);
$ev('p1', ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
$pilot->boardTick(time() + 12000);
check('pilot board: never when the user already answered, never a permission', count($asked) === $n);

// the assistant's own reply does not score itself; a stopped session is left alone
$r = $finish('f1', 'Shall I add a test?'); $hub->stop('claude-code:f1'); $n = count($asked);
$pilot->boardTick(time() + 13000);
check('pilot board: a stopped session is left alone', count($asked) === $n);

Pilot::$model = null;
foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
