<?php
declare(strict_types=1);
// 1.52.0: the board's ladder follows the coding agents' (Talixio Code), with the PO's level apart from the business:
// L1 technical, L2 design, L3 solution, L4 product (the PO, the user), L5 business, L6 values. L1-L3 raise their own
// autonomy once the assistant is right often enough; L4 too if the user allows it; L5 only by the user; L6 stays the
// user's. A level the agent states on its card is kept (never lowered). A proposal nobody decided comes back as a reminder.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Pilot};

$d = test_dir('pilot-ladder');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = [];
$hub = $app->agentHub(); $hub->token();
$pilot = $app->pilot();
$asked = []; $reply = '';
$before = Pilot::$model;
Pilot::$model = function (string $p) use (&$asked, &$reply) { $asked[] = $p; return $reply; };
$ev = fn(string $sid, array $e) => $hub->event($e + ['session_id' => $sid, 'cwd' => '/home/me/shop'], 'laptop');
$finish = function (string $sid, string $msg) use ($ev) { $ev($sid, ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Go']); return $ev($sid, ['hook_event_name' => 'Stop', 'last_assistant_message' => $msg])[1]; };
$notes = fn(string $like) => array_values(array_filter($app->store->q('SELECT title, body FROM notifications ORDER BY id')->fetchAll(), fn($n) => str_contains($n['title'], $like)));
$pred = fn(string $sid, int $seq) => $pilot->state()['predictions'][Pilot::boardKey("claude-code:$sid", $seq)] ?? null;
$t = time();

// the ladder the agents use
$p = $pilot->boardPrompt(['id' => 'x', 'seq' => 1, 'cwd' => '/p', 'machine' => '', 'last_message' => 'Go on?']);
check('pilot ladder: the model gets the six levels (technical, design, solution, product, business, values)', str_contains($p, 'L1 technical') && str_contains($p, 'L2 design')
    && str_contains($p, 'L3 solution') && str_contains($p, 'L4 product') && str_contains($p, 'L5 business') && str_contains($p, 'L6 values'), $p);

// L4 and L5 are the user's to open; L6 never
check('pilot ladder: the user can give L4 and L5 room', is_array($pilot->setPolicy(['autonomy' => ['L4' => 'tell', 'L5' => 'tell']])) && $pilot->state()['autonomy']['L4'] === 'tell' && $pilot->state()['autonomy']['L5'] === 'tell');
check('pilot ladder: L6 stays the user\'s', is_string($pilot->setPolicy(['autonomy' => ['L6' => 'tell']])) && $pilot->state()['autonomy']['L6'] === 'ask'
    && $pilot->overview()['levels']['L6']['kept'] && !$pilot->overview()['levels']['L5']['kept']);
$pilot->setPolicy(['autonomy' => ['L4' => 'ask', 'L5' => 'ask']]);
check('pilot ladder: a card\'s level is read, the agents\' L5 (values) as L6', Pilot::cardLevel("Niveau : L4\nA) x") === 'L4' && Pilot::cardLevel('Level: **L3**') === 'L3'
    && Pilot::cardLevel("Question\nL5 — valeurs") === 'L6' && Pilot::cardLevel('Version 2 is ready, shall I go on?') === null && Pilot::levelOf('') === 'L6');

// L1 raises itself once right often enough (answering alone switched on)
$pilot->setPolicy(['enabled' => true, 'threshold' => 0.5]);
check('pilot ladder: self-promotion is on, at 85 %, out of the box (not for L4)', $pilot->overview()['self_promote'] === true && $pilot->overview()['promote_at'] === 0.85 && $pilot->overview()['promote_po'] === false);
$round = function (string $sid, string $level, bool $right) use ($finish, &$reply, $pilot, $hub, &$t) {
    $r = $finish($sid, 'Which one? A) x B) y');
    $reply = json_encode(['kind' => 'answer', 'summary' => 's', 'answer' => 'A', 'level' => $level, 'confidence' => 0.9, 'rationale' => 'r']);
    $t += 300; $pilot->boardTick($t);
    if ($pilot->state()['predictions'][Pilot::boardKey("claude-code:$sid", $r['seq'])]['action'] === 'proposed') $hub->command("claude-code:$sid", 'reply', $right ? 'A' : 'B');
};
for ($i = 0; $i < 9; $i++) $round("l1-$i", 'L1', $i !== 4); // 8 of 9 right
check('pilot ladder: not before 10 answers', $pilot->state()['autonomy']['L1'] === 'ask');
$round('l1-9', 'L1', true); // 9 of 10 = 90 %
check('pilot ladder: 90 % over 10 answers raises L1 by itself to « tell »', $pilot->state()['autonomy']['L1'] === 'tell');
check('pilot ladder: and says so', count($notes('autonomy raised')) === 1, json_encode($notes('autonomy')));

// L4 learns, and raises itself only once the user allows it; L5 never
for ($i = 0; $i < 12; $i++) $round("l4-$i", 'L4', true);
check('pilot ladder: L4 does not raise itself unless allowed, it only suggests it', $pilot->state()['autonomy']['L4'] === 'ask' && $pilot->overview()['levels']['L4']['suggest'] === 'tell');
$pilot->setPolicy(['promote_po' => true]);
$round('l4-last', 'L4', true);
check('pilot ladder: allowed, L4 raises itself too', $pilot->state()['autonomy']['L4'] === 'tell');
$pilot->setPolicy(['promote_po' => false, 'autonomy' => ['L4' => 'ask']]);
for ($i = 0; $i < 12; $i++) $round("l5-$i", 'L5', true);
check('pilot ladder: L5 (business) never raises itself', $pilot->state()['autonomy']['L5'] === 'ask');

// off, nothing raises itself
$pilot->setPolicy(['self_promote' => false]);
for ($i = 0; $i < 10; $i++) $round("l2-$i", 'L2', true);
check('pilot ladder: with self-promotion off, nothing raises itself', $pilot->state()['autonomy']['L2'] === 'ask');
$pilot->setPolicy(['self_promote' => true]);

// a level the agent states on its card is kept, never lowered by the model
$r = $finish('card', "Question pour le PO\nNiveau : L4\nFaut-il livrer le lot 2 avant le lot 3 ?\nA) oui B) non\nRecommandation : A");
$reply = '{"kind":"answer","summary":"s","answer":"A","level":"L1","confidence":0.99,"rationale":"r"}';
$t += 1000; $pilot->boardTick($t);
check('pilot ladder: the card\'s level wins over a lower guess', ($pred('card', $r['seq'])['level'] ?? '') === 'L4' && $pred('card', $r['seq'])['action'] === 'proposed', json_encode($pred('card', $r['seq'])));

// a proposal nobody decided comes back
$n0 = count($notes('Still waiting'));
$pilot->boardTick($t + 1800);
check('pilot ladder: no reminder within the hour', count($notes('Still waiting')) === $n0);
$pilot->boardTick($t + 3700);
check('pilot ladder: after an hour, a reminder', count($notes('Still waiting')) === $n0 + 1);
$pilot->boardTick($t + 3800);
check('pilot ladder: not twice in a row', count($notes('Still waiting')) === $n0 + 1);
$pilot->boardTick($t + 3700 + 4 * 3600);
check('pilot ladder: then again later', count($notes('Still waiting')) === $n0 + 2);
$hub->command('claude-code:card', 'reply', 'A');
$pilot->boardTick($t + 3700 + 30 * 3600);
check('pilot ladder: once decided, no more reminders', count(array_filter($notes('Still waiting'), fn($n) => str_contains($n['body'], 'shop'))) === $n0 + 2);

Pilot::$model = $before;
foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
