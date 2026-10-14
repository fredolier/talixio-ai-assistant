<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Config, Http, Privacy, Search, Telegram, Tools, Agent, Mind, Abort};

$d = test_dir('tools');
$envKeys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS', 'TALIXIO_SHELL'];
$envSaved = array_combine($envKeys, array_map(fn($k) => getenv($k), $envKeys));
$hookSaved = Http::$hook;
$inWorkerSaved = App::$inWorker;
$usage = fn(callable $on, string $model = 'claude-sonnet-x', int $in = 1000, int $out = 200) => $on(['model' => $model, 'in' => $in, 'out' => $out, 'cache_read' => 0, 'cache_write' => 0, 'cost' => null]);
$caught = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };
$mkApp = function (string $name, array $env = []) use ($d): App {
    putenv("TALIXIO_DATA_DIR=$d/$name"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
    foreach ($env as $k => $v) putenv("$k=$v");
    $app = App::fromEnv();
    foreach ($env as $k => $v) putenv($k);
    return $app;
};

// ---------- a fake server on 127.0.0.1 for searxng, Gemini grounding and the Telegram bot API ----------
file_put_contents("$d/fake.php", <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$dir = __DIR__;
header('Content-Type: application/json');
if ($p === '/sx/search') {
    if (($q['q'] ?? '') === 'boom') { http_response_code(500); echo '{"error":1}'; return; }
    if (($q['q'] ?? '') === 'empty') { echo '{"results":[]}'; return; }
    $r = [];
    for ($i = 1; $i <= 10; $i++) $r[] = ['title' => "Acme $i", 'url' => "https://acme.example/$i", 'content' => "snippet $i"];
    echo json_encode(['results' => $r]); return;
}
if (str_starts_with($p, '/gm/models/')) {
    file_put_contents("$dir/gemini.log", $p . "\n", FILE_APPEND);
    $b = (string)file_get_contents('php://input');
    if (str_contains($b, 'nothingatall')) { echo '{"candidates":[{"content":{"parts":[]}}]}'; return; }
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Acme was '], ['text' => 'founded in 1999.']]],
        'groundingMetadata' => ['groundingChunks' => [['web' => ['uri' => 'https://acme.example/about', 'title' => 'About Acme']], ['retrievedContext' => []]]]]]]);
    return;
}
if (str_starts_with($p, '/botT/')) {
    $m = substr($p, 6);
    file_put_contents("$dir/tg.log", $m . ' ' . ($_SERVER['QUERY_STRING'] ?? '') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
    if ($m === 'getUpdates') { echo is_file("$dir/updates.json") ? file_get_contents("$dir/updates.json") : '{"ok":true,"result":[]}'; return; }
    if ($m === 'getFile') { echo ($q['file_id'] ?? '') === 'gone' ? '{"ok":false}' : '{"ok":true,"result":{"file_path":"voice/a.ogg"}}'; return; }
    echo '{"ok":true,"result":{}}'; return;
}
http_response_code(404); echo '{}';
PHP);
$fport = free_port();
$fp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fport", "$d/fake.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$d/fake.out", 'w'], 2 => ['file', "$d/fake.out", 'a']], $pipes);
register_shutdown_function(function () use ($fp) { proc_terminate($fp); proc_close($fp); });
wait_port($fport);
$fake = "http://127.0.0.1:$fport";

// ---------- Search ----------
$cfg = new Config();
Http::$hook = null;
$cfg->search = 'off';
check('search: an unconfigured backend is an error', $caught(fn() => Search::run($cfg, 'x')) === 'search is not configured');
$cfg->search = 'searxng'; $cfg->searxngUrl = "$fake/sx";
$r = Search::run($cfg, 'acme widgets');
check('search: searxng results are title, url and snippet blocks', str_starts_with($r, "Acme 1\nhttps://acme.example/1\nsnippet 1\n\nAcme 2"), $r);
check('search: at most 8 results are kept', str_contains($r, 'Acme 8') && !str_contains($r, 'Acme 9'), $r);
check('search: no results says so', Search::run($cfg, 'empty') === 'no results');
check('search: an HTTP error from the backend is an error', $caught(fn() => Search::run($cfg, 'boom')) === 'search error 500');
$cfg->search = 'gemini'; $cfg->geminiBaseUrl = "$fake/gm"; $cfg->geminiKey = 'k-demo';
$r = Search::run($cfg, 'acme history');
check('search: gemini gives a summary then the grounding links', str_starts_with($r, "Search summary (Google, via Gemini)\n\nAcme was founded in 1999.") && str_contains($r, "About Acme\nhttps://acme.example/about"), $r);
check('search: gemini without text or links is no results', Search::run($cfg, 'nothingatall') === 'no results');
check('search: gemini uses the standard fallback model for other providers', str_contains((string)file_get_contents("$d/gemini.log"), '/gm/models/' . \Talixio\GeminiModels::FALLBACK['standard'] . ':generateContent'));
$seen = [];
Http::$hook = function (string $url) use (&$seen) { $seen[] = [$url, Privacy::$purpose ?? null]; throw new \RuntimeException('offline in tests'); };
$cfg->search = 'brave'; $cfg->braveKey = 'b-demo';
check('search: brave goes to the Brave API', $caught(fn() => Search::run($cfg, 'a b')) === 'offline in tests' && str_starts_with($seen[0][0], 'https://api.search.brave.com/res/v1/web/search?count=8&q=a%20b'), json_encode($seen));
check('search: runs under the search purpose', ($seen[0][1] ?? '') === 'search', json_encode($seen));
$cfg->search = 'tavily'; $cfg->tavilyKey = 't-demo';
check('search: tavily goes to the Tavily API', $caught(fn() => Search::run($cfg, 'q')) === 'offline in tests' && $seen[1][0] === 'https://api.tavily.com/search');
Http::$hook = $hookSaved;

// ---------- Telegram ----------
$fm = new FakeModel();
$fm->script = fn($last) => "echo: $last";
$tapp = $mkApp('tg');
$tapp->providerOverride = $fm;
$tapp->cfg->telegramToken = 'T'; $tapp->cfg->telegramAllowed = [42]; $tapp->cfg->name = 'Alex';
$tg = new Telegram($tapp->cfg, $tapp);
\Closure::bind(fn() => $this->api = "$fake/botT", $tg, Telegram::class)();
$prevHook = Http::$hook;
Http::$hook = function (string $url) { if (!str_starts_with($url, 'http://127.0.0.1')) throw new \RuntimeException('no internet in tests'); };
$tg->send(42, str_repeat('a', 4500));
$log = (string)@file_get_contents("$d/tg.log");
check('telegram: long messages go out in 4000-char parts', substr_count($log, 'sendMessage') === 2, $log);
@unlink("$d/tg.log");
file_put_contents("$d/updates.json", json_encode(['ok' => true, 'result' => [
    ['update_id' => 10, 'message' => ['chat' => ['id' => 7], 'text' => 'hello']],
    ['update_id' => 11, 'message' => ['text' => 'no chat']],
    ['update_id' => 12, 'message' => ['chat' => ['id' => 42]]],
    ['update_id' => 13, 'message' => ['chat' => ['id' => 42], 'text' => '/start']],
    ['update_id' => 14, 'message' => ['chat' => ['id' => 42], 'text' => 'What is two plus two?']],
    ['update_id' => 15, 'message' => ['chat' => ['id' => 42], 'caption' => 'listen', 'voice' => ['file_id' => 'v1', 'file_size' => 100]]],
    ['update_id' => 16, 'message' => ['chat' => ['id' => 42], 'document' => ['file_id' => 'gone', 'file_size' => 10]]],
    ['update_id' => 17, 'message' => ['chat' => ['id' => 42], 'photo' => [['file_id' => 'p0'], ['file_id' => 'p1', 'file_size' => 30_000_000]]]],
]]));
$tg->poll(0);
$log = (string)@file_get_contents("$d/tg.log");
$sent = array_values(array_filter(explode("\n", $log), fn($l) => str_starts_with($l, 'sendMessage')));
$texts = array_map(fn($l) => json_decode(substr($l, strpos($l, '{')), true)['text'] ?? '', $sent);
check('telegram: the update offset is stored', $tapp->store->get('telegram_offset', '') === '18');
check('telegram: an unknown chat is told its id', in_array('This assistant is private. Your chat id is 7.', $texts, true), json_encode($texts));
check('telegram: /start greets with the name', in_array("Hi, I'm Alex. Ask me anything.", $texts, true), json_encode($texts));
check('telegram: a text message is answered by the agent', (bool)array_filter($texts, fn($t) => str_contains($t, 'two plus two')), json_encode($texts));
check('telegram: a voice note whose download fails gets an apology', (bool)array_filter($texts, fn($t) => str_starts_with($t, "I couldn't process that")), json_encode($texts));
check('telegram: updates without chat or content are skipped', count($sent) === 6, json_encode($texts));
check('telegram: a file Telegram no longer has is reported', str_contains($log, 'getFile file_id=gone'), $log);
file_put_contents("$d/updates.json", '{"ok":true,"result":[]}');
$tg->poll(0);
check('telegram: polling resumes from the stored offset', str_contains((string)file_get_contents("$d/tg.log"), 'getUpdates timeout=0&offset=18'));
Http::$hook = $prevHook;

// ---------- Mind ----------
$mapp = $mkApp('mind');
$mfm = new FakeModel();
$mnext = '';
$mfm->script = function ($last) use (&$mnext) { return is_callable($mnext) ? $mnext($last) : $mnext; };
$mapp->providerOverride = $mfm;
$mapp->cfg->provider = 'anthropic'; $mapp->cfg->mind = true; $mapp->cfg->budgetDaily = 0; $mapp->cfg->budgetMonthly = 0;
$m = $mapp->mind();
$now = time();
check('mind: heartbeat rows are not observed', $m->observe([['id' => 1, 'ts' => $now, 'role' => 'user', 'content' => 'x', 'channel' => 'heartbeat']]) === 0 && !$mfm->calls);
check('mind: an unknown observation kind is refused', str_starts_with($caught(fn() => $m->addObservation('mood', 'x', 2)), 'kind: value'));
check('mind: nothing to consolidate', $m->consolidate($now) === false);
check('mind: no beliefs, no portrait', $m->reflect($now) === false);
$acme = $mapp->spaces()->save(['name' => 'Acme', 'instructions' => 'Be terse with Acme clients.'])['id'];
$quiet = $mapp->spaces()->save(['name' => 'Quiet'])['id'];
$mnext = '{"observations":[{"kind":"value","text":"Values punctuality","strength":3,"messages":[5,99]},{"kind":"bogus","text":"x"},{"kind":"self","text":""}]}';
check('mind: observe keeps only valid observations', $m->observe([['id' => 5, 'ts' => $now, 'role' => 'user', 'content' => 'Be on time please', 'channel' => 'web']], $acme) === 1);
$o = [];
foreach ([[ 'value', 'Prefers short answers', 3, 0, $now - 3 * 86400], ['value', 'Prefers short answers again', 3, 0, $now],
          ['pattern', 'Compares options side by side', 2, $acme, $now], ['self', 'Was too long once', 1, 0, $now]] as [$k, $t, $st, $sp, $ts])
    $o[] = $m->addObservation($k, $t, $st, $sp, 'chat', [], $ts);
$mnext = '';
check('mind: an empty model reply consolidates nothing', $m->consolidate($now) === false);
$mnext = json_encode(['ops' => [
    ['op' => 'new', 'kind' => 'value', 'scope' => 'global', 'text' => 'Likes brevity', 'obs' => [$o[0], $o[1]]],
    ['op' => 'new', 'kind' => 'pattern', 'scope' => 'Acme', 'text' => 'Compares offers in a table', 'obs' => [$o[2]]],
    ['op' => 'new', 'kind' => 'self', 'text' => 'Keep it short', 'obs' => [$o[3]]],
    ['op' => 'new', 'kind' => 'mood', 'text' => 'x', 'obs' => [$o[3]]],
    ['op' => 'new', 'kind' => 'self', 'text' => 'no evidence', 'obs' => [12345]],
]]);
check('mind: consolidate creates beliefs', $m->consolidate($now) === true);
$bel = $mapp->store->q('SELECT id, text, space, status FROM mind_beliefs ORDER BY id')->fetchAll();
check('mind: only valid new beliefs are kept, scoped by space name', count($bel) === 3 && (int)$bel[1]['space'] === $acme && (int)$bel[0]['space'] === 0, json_encode($bel));
check('mind: two days of strong evidence make a belief established', $bel[0]['status'] === 'established', json_encode($bel));
$journal = $mapp->store->q("SELECT text FROM mind_journal WHERE kind = 'changes' ORDER BY id DESC")->fetchColumn();
check('mind: new beliefs go to the journal', str_contains((string)$journal, 'New: Likes brevity'), (string)$journal);
[$b1, $b2, $b3] = array_map(fn($b) => (int)$b['id'], $bel);
$o2 = [];
for ($i = 0; $i < 4; $i++) $o2[] = $m->addObservation('value', "Accepted a long report $i", 3, 0, 'chat', [], $now);
$o2[] = $m->addObservation('pattern', 'Tables again', 2, $acme, 'chat', [], $now - 86400);
$mnext = "Here you go:\n" . json_encode(['ops' => [
    ['op' => 'contradict', 'belief' => $b1, 'obs' => $o2],
    ['op' => 'support', 'belief' => $b2, 'obs' => [$o2[4]]],
    ['op' => 'rewrite', 'belief' => $b2, 'text' => 'Compares offers side by side in a table'],
    ['op' => 'rewrite', 'belief' => 9999, 'text' => 'ignored'],
    ['op' => 'merge', 'beliefs' => [$b2, $b3], 'text' => 'Wants offers compared in a short table'],
    ['op' => 'merge', 'beliefs' => [$b2], 'text' => 'one is not a merge'],
    ['op' => 'support', 'belief' => 9999, 'obs' => [$o2[0]]],
]]);
check('mind: consolidate applies support, contradict, rewrite and merge', $m->consolidate($now) === true);
$after = array_column($mapp->store->q('SELECT id, text, status, merged_into FROM mind_beliefs')->fetchAll(), null, 'id');
check('mind: a merge keeps the first belief with the new text', $after[$b2]['text'] === 'Wants offers compared in a short table' && $after[$b3]['status'] === 'dropped' && (int)$after[$b3]['merged_into'] === $b2, json_encode($after));
check('mind: contradictions lower an established belief', $after[$b1]['status'] !== 'established', json_encode($after));
$journal = (string)$mapp->store->q("SELECT text FROM mind_journal WHERE kind = 'changes' ORDER BY id DESC")->fetchColumn();
check('mind: the journal says what became less sure and what faded', str_contains($journal, 'Less sure: Likes brevity') && str_contains($journal, 'Faded: Keep it short'), $journal);
check('mind: forget marks a belief', $m->forget($b1) === true && $m->forget($b1) === false);
$m->addObservation('self', 'Answered in French', 2, 0);
$prompt = '';
$mnext = function ($last) use (&$prompt) { $prompt = $last; return 'no json here'; };
check('mind: a reply without JSON still marks observations processed', $m->consolidate($now) === true && (int)$mapp->store->q('SELECT COUNT(*) FROM mind_obs WHERE processed = 0')->fetchColumn() === 0);
check('mind: forgotten beliefs are listed as never again', str_contains($prompt, "Forgotten at the user's request (never again)\n- Likes brevity"), $prompt);
// reflect
$mnext = '';
check('mind: an empty reflection changes nothing', $m->reflect($now) === false);
$mnext = "---\nonly a journal entry";
check('mind: a reflection without a portrait changes nothing', $m->reflect($now) === false && $mapp->store->get('mind_portrait') === '');
$mnext = function ($last) use (&$prompt) { $prompt = $last; return "I am Alex's concise helper.\n---\nI learned to be brief."; };
check('mind: reflect writes the portrait and a journal entry', $m->reflect($now) === true && $mapp->store->get('mind_portrait') === "I am Alex's concise helper."
    && $mapp->store->q("SELECT text FROM mind_journal WHERE kind = 'entry'")->fetchColumn() === 'I learned to be brief.');
check('mind: space beliefs are marked in the reflection', str_contains($prompt, 'only in the space Acme'), $prompt);
// reflectSpace
check('mind: General has no space portrait', $m->reflectSpace(0, $now) === false);
check('mind: an unknown space has no portrait', $m->reflectSpace(9999, $now) === false);
check('mind: a space without material has no portrait', $m->reflectSpace($quiet, $now) === false);
$mnext = 'NOTHING.';
check('mind: NOTHING means no space portrait yet', $m->reflectSpace($acme, $now) === false && $mapp->store->get("mind_reflected_$acme") === (string)$now);
$mnext = function ($last) use (&$prompt) { $prompt = $last; return "With Acme I stay terse.\n---\nShorter there."; };
check('mind: a space portrait is written', $m->reflectSpace($acme, $now) === true && $mapp->store->get("mind_portrait_$acme") === 'With Acme I stay terse.');
check('mind: the space instructions are in the prompt', str_contains($prompt, "The user's instructions for this space\nBe terse with Acme clients."), $prompt);
check('mind: the same space portrait again is no change', $m->reflectSpace($acme, $now) === false);
check('mind: the section shows the portrait in this space', str_contains($m->section($acme), "### In this space (Acme)\nWith Acme I stay terse."));
// thinkNow: consolidate (nothing new), reflect, then each space with material
$asked = [];
$mnext = function ($last) use (&$asked) {
    $asked[] = str_contains($last, 'self-portrait') ? 'reflect' : (str_contains($last, 'Write how you are in the space') ? 'space' : 'other');
    return str_contains($last, 'self-portrait') ? "I am steady.\n---\nSteadier." : "In Acme: tables first.";
};
check('mind: thinkNow reflects and portrays each space with material', $m->thinkNow($now) === true && $asked === ['reflect', 'space'], json_encode($asked));
// ask() returns null when the budget is spent
$mapp->cfg->budgetDaily = 1.0;
$mapp->store->addUsage(['model' => 'x', 'in' => 1, 'out' => 1], 5.0, 'test', 'standard', null);
$m->addObservation('self', 'Something new', 2, 0);
$n = count($mfm->calls);
check('mind: a spent budget skips the model', $m->consolidate($now) === false && count($mfm->calls) === $n);
check('mind: tick does nothing over budget', $m->tick($now) === false);
$mapp->cfg->budgetDaily = 0;

// tick: learn from the history, then consolidate, reflect, portray spaces, decay
$kapp = $mkApp('mindtick');
$kfm = new FakeModel();
$kfm->script = function ($last) {
    if (str_contains($last, 'Notice what it shows')) return '{"observations":[{"kind":"pattern","text":"Plans the week on Monday","strength":3}]}';
    if (str_contains($last, 'Fold the new observations')) { preg_match_all('/^O(\d+) /m', $last, $mm); return json_encode(['ops' => [['op' => 'new', 'kind' => 'pattern', 'scope' => 'Acme', 'text' => 'Plans weekly', 'obs' => array_map('intval', $mm[1])]]]); }
    if (str_contains($last, 'self-portrait')) return "I plan with Alex.\n---\nNew.";
    if (str_contains($last, 'Write how you are in the space')) return "In Acme I plan.\n---\nNew there.";
    return '';
};
$kapp->providerOverride = $kfm;
$kapp->cfg->mind = true;
$km = $kapp->mind();
check('mind: tick is off with the echo provider', $km->tick($now) === false && !$km->enabled());
$kapp->cfg->provider = 'anthropic';
$kacme = $kapp->spaces()->save(['name' => 'Acme'])['id'];
$kapp->store->addSummary(1, 2, $now - 86400, $now - 86000, 'Alex planned the week.', 1, 0);
$kapp->store->addSummary(3, 4, $now - 86000, $now - 85000, 'Alex planned again for Acme.', 1, $kacme);
check('mind: tick first learns from the compacted history', $km->tick($now) === true && (int)$kapp->store->q('SELECT COUNT(*) FROM mind_obs')->fetchColumn() === 2);
check('mind: then the bootstrap finishes', $km->tick($now) === false && $kapp->store->get('mind_bootstrap') === 'done');
check('mind: pending observations are consolidated after a day', $km->tick($now + 21 * 3600) === true && (int)$kapp->store->q('SELECT COUNT(*) FROM mind_beliefs')->fetchColumn() === 1);
check('mind: then it reflects weekly', $km->tick($now + 22 * 3600) === true && $kapp->store->get('mind_portrait') === 'I plan with Alex.');
check('mind: then it portrays a space with its own beliefs', $km->tick($now + 23 * 3600) === true && $kapp->store->get("mind_portrait_$kacme") === 'In Acme I plan.');
check('mind: then it only lets beliefs fade, once a day', $km->tick($now + 24 * 3600) === false && $kapp->store->get('mind_decayed') === (string)($now + 24 * 3600));
for ($i = 0; $i < 40; $i++) $km->addObservation('value', "obs $i", 1);
check('mind: 40 pending observations are consolidated right away', $km->tick($now + 25 * 3600) === true);

// ---------- Tools: static helpers ----------
check('tools: loginFile expands ~/ under the first root', Tools::loginFile('~/.strider/acme/cookies.json', ["$d/home", $d]) === "$d/home/.strider/acme/cookies.json");
check('tools: loginFile wants an absolute path', str_starts_with(Tools::loginFile('cookies.json', [$d]), 'error: give the full path'));
check('tools: loginFile refuses /../', str_starts_with(Tools::loginFile("$d/a/../b.json", [$d]), 'error: give the full path'));
check('tools: loginFile wants a .json file', str_starts_with(Tools::loginFile("$d/a.txt", [$d]), 'error: give the full path'));
check('tools: loginFile keeps to the roots', Tools::loginFile('/etc/acme.json', [$d]) === "error: the cookie file must be in the server's home or data folder");
check('tools: loginFile accepts a file in a root', Tools::loginFile("$d/x/c.json", ['', $d]) === "$d/x/c.json");
check('tools: a cookie list is a cookie file', Tools::isCookieFile([]) && Tools::isCookieFile([['name' => 'sid', 'domain' => 'acme.example']]) && !Tools::isCookieFile([['x' => 1]]));
check('tools: a storage state is a cookie file', Tools::isCookieFile(['cookies' => [], 'origins' => []]) && !Tools::isCookieFile(['cookies' => 'x']) && !Tools::isCookieFile('text'));
$t0 = strtotime('2026-10-14 09:00');
check('tools: when reads plain words', Tools::when(['when' => 'every monday at 8:00'], $t0)['cron'] !== null);
check('tools: when falls back to cron', Tools::when(['cron' => '0 8 * * 1'], $t0)['cron'] === '0 8 * * 1');
check('tools: when falls back to at', Tools::when(['at' => '2026-10-20T10:00'], $t0) === ['cron' => null, 'next' => strtotime('2026-10-20 10:00')]);
check('tools: when without a schedule is an error', str_starts_with($caught(fn() => Tools::when([], $t0)), 'say when'));
check('tools: outside content sources are untrusted', Tools::untrustedSource('mcp_x_y') && Tools::untrustedSource('browser_open') && Tools::untrustedSource('mail_read') && !Tools::untrustedSource('kb_search'));

// ---------- Tools: library, memory, shares, jobs ----------
$app = $mkApp('tl');
$app->cfg->approvalWaitSeconds = 0;
$T = $app->tools;
$x = fn(string $n, array $a = []) => $app->tools->execute($n, $a);
$app->cfg->mind = false;
check('tools: mind_observe says when learning is off', $x('mind_observe', ['kind' => 'value', 'text' => 'x']) === 'learning is off');
$app->cfg->mind = true;
check('tools: mind_observe notes it', $x('mind_observe', ['kind' => 'self', 'text' => 'Short replies please']) === 'noted');
check('tools: memory_replace needs the old text', $x('memory_replace', ['file' => 'USER.md', 'old_text' => 'not there at all', 'new_text' => 'x']) === 'error: old_text not found');
check('tools: kb_list of a new library shows its folders', str_contains($x('kb_list'), "/Inbox (0 files)\n/Meetings (0 files)"), $x('kb_list'));
check('tools: kb_search says where it looked', $x('kb_search', ['query' => 'zebra', 'folder' => '/Clients']) === "nothing found in /Clients (search folder '/' to look in the whole library)");
check('tools: kb_search in the whole library', $x('kb_search', ['query' => 'zebra']) === 'nothing found in /');
check('tools: kb_find with no match', str_starts_with($x('kb_find', ['query' => 'zebra']), "no file or folder named like 'zebra'"));
check('tools: kb_trash when empty', $x('kb_trash', ['action' => 'list']) === 'the trash is empty');
check('tools: kb_inbox when empty', $x('kb_inbox') === 'the inbox is empty');
$app->library()->ensureFolder('/Clients/Acme');
check('tools: kb_list of an empty folder', $x('kb_list', ['folder' => '/Clients/Acme']) === '/Clients/Acme is empty');
$tmp = tap_tmp("Acme invoice 42\nTotal 100 EUR"); $f1 = $app->files()->save($tmp, 'invoice.txt', 'text/plain', '/Inbox');
$tmp = tap_tmp("Acme quote"); $f2 = $app->files()->save($tmp, 'quote.txt', 'text/plain', '/Inbox');
$app->store->q("UPDATE files SET suggestion = ? WHERE id = ?", [json_encode(['folder' => '/Clients/Acme', 'name' => 'Invoice 42.txt', 'confidence' => 'high', 'why' => 'like the others']), $f1]);
$app->store->q("UPDATE files SET status = 'pending' WHERE id = ?", [$f2]);
$r = $x('kb_inbox');
check('tools: kb_inbox gives the suggested place', str_contains($r, "#$f1 invoice.txt → /Clients/Acme/Invoice 42.txt (high confidence: like the others)"), $r);
check('tools: kb_inbox marks files still being read', str_contains($r, "#$f2 quote.txt (still being read)"), $r);
check('tools: kb_list shows a file that is not ready', str_contains($x('kb_list', ['folder' => '/Inbox']), ', pending)'), $x('kb_list', ['folder' => '/Inbox']));
check('tools: kb_list without a folder shows the tree', str_contains($x('kb_list'), '/Clients'), $x('kb_list'));
$tmp = tap_tmp("old"); $f3 = $app->files()->save($tmp, 'old.txt', 'text/plain', '/Clients/Acme');
$tmp = tap_tmp("old2"); $f4 = $app->files()->save($tmp, 'old2.txt', 'text/plain', '/Clients/Acme');
$tmp = tap_tmp("single"); $f5 = $app->files()->save($tmp, 'single.txt', 'text/plain', '/Inbox');
$app->library()->deleteFile($f5);
$app->library()->deleteFolder('/Clients/Acme', true);
$r = $x('kb_trash', ['action' => 'list']);
check('tools: kb_trash lists files and folders', str_contains($r, "- file #$f5 /Inbox/single.txt") && str_contains($r, '- folder /Clients/Acme with 2 files (old.txt, old2.txt)'), $r);
$at = (int)$app->store->q('SELECT deleted_at FROM files WHERE id = ?', [$f3])->fetchColumn();
check('tools: kb_trash restores a folder', $x('kb_trash', ['action' => 'restore', 'folder' => '/Clients/Acme', 'deleted_at' => $at]) === 'restored 2 files to /Clients/Acme');
check('tools: kb_trash restores one file', $x('kb_trash', ['action' => 'restore', 'id' => $f5]) === 'restored 1 file to /Inbox');
check('tools: kb_find finds a folder and a file', str_contains($r = $x('kb_find', ['query' => 'acme']), '- folder /Clients/Acme (2 files)'), $r);
check('tools: routine_feedback by name is noted', $x('routine_feedback', ['routine' => 'Morning briefing', 'item' => 'weather', 'vote' => 'down']) === 'noted: its next runs will take it into account');
check('tools: routine_feedback by an unknown id is an error', $x('routine_feedback', ['routine' => '7', 'item' => 'news', 'vote' => 'up']) === 'error: no such routine');
// shares
$r = $x('share_create', ['file_id' => $f1, 'for' => 'Alex', 'days' => 0, 'password' => 'secret1', 'download' => false]);
check('tools: share_create without a known address gives the path', str_starts_with($r, 'link created (#1) but the app\'s address is unknown'), $r);
$app->store->set('public_url', 'https://talixio.acme.example');
$r = $x('share_create', ['folder' => '/Clients/Acme', 'days' => 7, 'password' => 'secret1', 'download' => false]);
check('tools: share_create gives the link with its terms', (bool)preg_match('#^link \#2: https://talixio\.acme\.example/s/\S+ \(until \d{4}-\d{2}-\d{2}\), password protected, view only$#', $r), $r);
$app->store->q('UPDATE shares SET last_access = ? WHERE id = 1', [time()]);
$r = $x('share_list');
check('tools: share_list shows who, state and last access', str_contains($r, '#1 ') && str_contains($r, '| for Alex') && str_contains($r, '(last '), $r);
check('tools: share_revoke an unknown link', $x('share_revoke', ['id' => 99]) === 'error: no such active link');
check('tools: share_revoke', $x('share_revoke', ['id' => 1]) === 'revoked' && $x('share_revoke', ['id' => 2]) === 'revoked');
check('tools: share_list with nothing active', $x('share_list') === 'no shared links');
$r = $T->run('share_create', ['file_id' => $f1, 'for' => 'Alex', 'days' => 0, 'password' => 'pw12', 'download' => false]);
check('tools: share_create asks first, with its terms', str_contains($r, 'Share "invoice.txt" with Alex by link, with no end date, view only, with a password'), $r);
$r = $T->run('share_create', ['folder' => '/Clients']);
check('tools: share_create of a folder asks first', str_contains($r, 'Share the folder /Clients by link, for 30 days'), $r);
// tasks and jobs
check('tools: cancel_task of an unknown task', $x('cancel_task', ['id' => 999]) === 'error: no such task');
check('tools: schedule_task needs a when', str_starts_with($x('schedule_task', ['title' => 'x', 'prompt' => 'y']), 'error: say when'));
$r = $x('delegate', ['title' => 'Acme research', 'instructions' => 'Find Acme prices', 'tags' => 'acme', 'max_usd' => 0.5, 'effort' => 'low', 'folder' => '/Clients']);
check('tools: delegate with a tag, a cap and a folder', (bool)preg_match('/^job #(\d+) queued \(standard tier, effort low, cap \$0\.50\).*saved in \/Clients when done\.$/', $r, $jm), $r);
$j1 = (int)($jm[1] ?? 0);
$r = $x('delegate', ['title' => 'Beta', 'instructions' => 'Something', 'tags' => ['beta', 'two'], 'tier' => 'fast']);
check('tools: delegate with a list of tags', str_contains($r, 'fast tier'), $r);
$r = $x('list_jobs', ['tag' => 'acme']);
check('tools: list_jobs by tag', str_contains($r, "#$j1 Acme research") && str_contains($r, 'tags: acme') && str_contains($r, 'Tags in use:'), $r);
check('tools: list_jobs across spaces names the space', str_contains($x('list_jobs', ['all_spaces' => true]), '| space General'));
check('tools: list_jobs with no match says what it looked for', str_starts_with($x('list_jobs', ['tag' => 'zzz', 'search' => 'nope', 'archived' => true]), 'no archived jobs tagged "zzz" matching "nope" in this space'), $x('list_jobs', ['tag' => 'zzz', 'search' => 'nope', 'archived' => true]));
$tmp = tap_tmp('report'); $rf = $app->files()->save($tmp, 'Report.md', 'text/markdown', '/Clients');
$app->store->updateJob($j1, ['report_file' => $rf, 'status' => 'done']);
check('tools: list_jobs links the report note', str_contains($x('list_jobs', ['search' => 'Acme']), "| report: [Report.md](/files/$rf) in /Clients"), $x('list_jobs', ['search' => 'Acme']));
$app->store->updateJob($j1, ['report_file' => 99999]);
check('tools: list_jobs says when the report note is gone', str_contains($x('list_jobs', ['search' => 'Acme']), '| report note deleted'));
check('tools: archive_job needs ids', $x('archive_job') === 'error: give ids or all_finished');
check('tools: archive_job by id and back', $x('archive_job', ['ids' => [$j1]]) === '1 job(s) archived' && $x('archive_job', ['ids' => [$j1], 'archived' => false]) === '1 job(s) back in the list');
check('tools: archive_job all finished', $x('archive_job', ['all_finished' => true]) === '1 job(s) archived');
check('tools: schedule_job of an unknown job is an error', str_starts_with($x('schedule_job', ['id' => 999, 'when' => 'every monday at 8:00']), 'error: '), $x('schedule_job', ['id' => 999, 'when' => 'every monday at 8:00']));
$r = $x('continue_job', ['id' => $j1, 'max_usd' => 1.25, 'effort' => 'high', 'note' => 'go deeper']);
check('tools: continue_job with a cap and an effort', (bool)preg_match('/^job #\d+ queued: continues #' . $j1 . ' \(cap \$1\.25, effort high\)/', $r), $r);
check('tools: cancel_job of an unknown job', $x('cancel_job', ['id' => 999]) === 'error: job not found or already finished');
$r = $x('usage_bill', ['amount' => 0.01, 'currency' => 'USD', 'month' => date('Y-m', strtotime('first day of last month'))]);
check('tools: usage_bill with too little to compare', str_contains($r, '(too little to compare)'), $r);
$app->store->addUsage(['model' => 'mystery-model', 'in' => 10, 'out' => 5, 'cache_read' => 0, 'cache_write' => 0], null, 'chat', 'standard', null);
check('tools: usage_report says when a cost is unknown', str_contains($x('usage_report'), 'cost unknown'));

// notes, spaces and space notes
$r = $x('kb_save_note', ['content' => '# Call with Alex', 'name' => 'Call']);
check('tools: kb_save_note goes to /Notes in General', (bool)preg_match('#^saved /Notes/Call\.md \(file \#\d+\)$#', $r), $r);
$spA = $app->spaces()->save(['name' => 'Acme', 'folder' => '/Clients/Acme']);
$spB = $app->spaces()->save(['name' => 'Beta']);
$app->space = (int)$spA['id'];
$r = $x('kb_save_note', ['content' => 'Acme notes', 'name' => 'Kickoff']);
check('tools: kb_save_note in a space goes to its Notes folder', str_starts_with($r, 'saved /Clients/Acme/Notes/Kickoff.md'), $r);
check('tools: space_notes read when empty', $x('space_notes', ['action' => 'read']) === '(no notes yet)');
check('tools: space_notes append', $x('space_notes', ['action' => 'append', 'text' => 'Alex is the contact']) === 'saved' && $x('space_notes', ['action' => 'read']) === "Alex is the contact\n");
check('tools: space_notes replace needs the old text', $x('space_notes', ['action' => 'replace', 'old_text' => 'Bob', 'new_text' => 'x']) === 'error: old_text not found');
check('tools: space_notes replace', $x('space_notes', ['action' => 'replace', 'old_text' => 'Alex', 'new_text' => 'Alex Martin']) === 'saved' && str_contains($x('space_notes', ['action' => 'read']), 'Alex Martin is the contact'));
check('tools: space_notes with an unknown action', $x('space_notes', ['action' => 'erase']) === 'error: action is read, append or replace');
check('tools: spaces with an unknown action', $x('spaces', ['action' => 'erase', 'space' => 'Acme']) === 'error: action is list, history or restore');
// board, from a space and from General
$r = $x('board_add', ['title' => 'Send the Acme quote', 'workload' => '2h']);
check('tools: board_add in a space', str_starts_with($r, 'added '), $r);
$codeA = $app->board()->list(['space' => (int)$spA['id']])[0]['code'];
check('tools: only General puts tasks in another space', $x('board_add', ['title' => 'x', 'space' => 'Beta']) === 'error: Only General can put tasks in another space.');
check('tools: board_list with nothing matching', $x('board_list', ['tag' => 'nothing']) === 'no tasks match');
check('tools: board_list from a space cannot see all spaces', str_starts_with($x('board_list', ['all_spaces' => true]), "error: only General sees"));
$r = $x('board_log_time', ['code' => $codeA, 'time' => '1h30']);
check('tools: board_log_time against the estimate', str_starts_with($r, "logged on $codeA: ") && str_contains($r, ' planned'), $r);
$r = $x('board_prioritize', ['codes' => [$codeA]]);
check('tools: board_prioritize in a space reorders', $r === "reordered: $codeA", $r);
check('tools: only General sets the overall order', $x('board_prioritize', ['codes' => [$codeA], 'overall' => true]) === 'error: only General sets the overall order.');
$r = $x('board_changes');
check('tools: board_changes lists who did what', str_contains($r, $codeA) && str_contains($r, '— by assistant in Acme'), $r);
$r = $T->run('board_invite', ['code' => $codeA]);
check('tools: board_invite asks first, naming the guests', str_contains($r, "Send the invitation $codeA \"Send the Acme quote\" to nobody"), $r);
$r = $T->run('board_invite', ['code' => $codeA, 'cancel' => true]);
check('tools: board_invite cancel asks first', str_contains($r, "Cancel the meeting $codeA"), $r);
$app->space = 0;
check('tools: board_changes in General', str_contains($x('board_changes'), $codeA));
check('tools: board_add to an unknown space', $x('board_add', ['title' => 'x', 'space' => 'Nowhere']) === 'error: no space "Nowhere"');
$r = $x('board_add', ['title' => 'Beta kickoff', 'space' => 'Beta']);
$codeB = $app->board()->list(['space' => (int)$spB['id']])[0]['code'] ?? '';
check('tools: General puts a task in another space', $codeB !== '' && str_starts_with($r, 'added '), $r);
$r = $x('board_get', ['code' => $codeA]);
check('tools: board_get in General gives the whole card', str_contains($r, 'Send the Acme quote'), $r);
$r = $x('board_prioritize', ['codes' => [$codeB, $codeA], 'overall' => true]);
check('tools: board_prioritize overall in General', $r === "overall order set: $codeB > $codeA", $r);
$r = $x('board_list', ['all_spaces' => true]);
check('tools: board_list of all spaces in overall order', str_starts_with($r, '## Open tasks of all spaces, in overall order') && strpos($r, $codeB) < strpos($r, $codeA), $r);
check('tools: board_list with nothing yet', (function () use ($app, $x, $spB) { $app->space = (int)$spB['id']; $app->board()->delete($app->board()->list(['space' => (int)$spB['id']])[0]['id'], 'test'); $r = $x('board_list'); $app->space = 0; return $r; })() === 'no tasks yet');
check('tools: board_log_time from another space is refused', (function () use ($app, $x, $spB, $codeA) { $app->space = (int)$spB['id']; $r = $x('board_log_time', ['code' => $codeA, 'time' => '1h']); $app->space = 0; return $r; })() === "error: $codeA belongs to another space: only that space or General can change it.");
// run(): the privacy gate, a failing approval request, repeated calls, untrusted content
$app->spaces()->save(['privacy' => 'secret'] + $spB, (int)$spB['id']);
$app->space = (int)$spB['id'];
$r = $T->run('fetch_url', ['url' => 'https://acme.example/']);
check('tools: a secret space blocks fetch_url before it runs', str_starts_with($r, 'error') && str_contains(strtolower($r), 'secret'), $r);
check('tools: the gate result is logged', $app->store->q("SELECT result FROM tool_log WHERE tool = 'fetch_url' ORDER BY id DESC LIMIT 1")->fetchColumn() === $r);
$app->space = 0;
$app->spaces()->save(['privacy' => 'internal'] + $app->spaces()->get((int)$spB['id']), (int)$spB['id']);
Privacy::$untrusted = true; Privacy::$links = ['https://acme.example/page' => true];
$r = $T->run('memory_append', ['file' => 'MEMORY.md', 'text' => 'Acme pays late']);
check('tools: after untrusted content a lasting change asks first', str_contains($r, "Waiting for the user's approval") && str_contains($r, 'Run memory_append'), $r);
check('tools: after untrusted content a space note change asks first', str_contains($T->run('space_notes', ['action' => 'append', 'text' => 'x']), 'Run space_notes'));
$r = $T->run('fetch_url', ['url' => 'https://acme.example/other']);
check('tools: after untrusted content an unknown address asks first', str_contains($r, 'Fetch the web address https://acme.example/other'), $r);
Privacy::trustAgain();
$app->cfg->autoApprove = array_values(array_diff($app->cfg->autoApprove, ['memory_append']));
$r = $T->forAnswer()->run('board_invite', ['code' => 'NOPE-404']);
check('tools: an approval whose summary fails is reported as an error', str_starts_with($r, 'error: ') && !str_contains($r, 'Waiting'), $r);
$fa = $T->forAnswer();
$fa->run('kb_find', ['query' => 'acme']); $fa->run('kb_find', ['query' => 'acme']);
check('tools: a third identical call is refused', str_starts_with($fa->run('kb_find', ['query' => 'acme']), 'error: you already made this exact call twice'));
// focus: files, connectors, tools_more
$fo = $T->forAnswer()->focus('hello there', true);
check('tools: focus with a file gives the library tools', in_array('kb_move', array_column($fo->schemas(), 'name'), true) && !in_array('board_add', array_column($fo->schemas(), 'name'), true));
$r = $fo->execute('tools_more', ['groups' => ['bogus']]);
check('tools: tools_more with an unknown group', $r === "no such group (see the list in this tool's description)", $r);
$r = $fo->execute('tools_more', ['groups' => [' tasks ']]);
check('tools: tools_more adds a group', str_starts_with($r, 'now available: ') && str_contains($r, 'board_list') && in_array('tasks', $fo->shownGroups(), true), $r);
$all = $T->forAnswer();
check('tools: tools_more cannot add to a toolset that sends everything', (fn() => $this->show(['board']))->call($all) === []);
check('tools: an unknown toolset is refused', $caught(fn() => $T->only('bogus')) === 'unknown toolset bogus');
// shell and scripts in "ask" mode (nothing runs: approvals time out at once)
$sapp = $mkApp('tlshell', ['TALIXIO_SHELL' => 'ask']);
$sapp->cfg->approvalWaitSeconds = 0;
$sx = fn(string $n, array $a = []) => $sapp->tools->execute($n, $a);
if (\Talixio\Shell::binary()) {
    check('shell: an empty command', $sx('shell_run', ['command' => '  ']) === 'error: empty command');
    check('shell: "ask" mode waits for the user', str_starts_with($sx('shell_run', ['command' => 'ls', 'files' => [1]]), 'error: the user did not approve this command'));
    App::$inWorker = true;
    check('shell: "ask" mode cannot wait in the worker', str_contains($sx('shell_run', ['command' => 'ls']), "can't be awaited here"));
    check('scripts: saving cannot wait in the worker', str_contains($sx('script_save', ['name' => 'Add', 'description' => 'd', 'code' => 'print(1)']), 'Run the code with shell_run instead'));
    App::$inWorker = false;
    check('scripts: saving waits for the user', $sx('script_save', ['name' => 'Add', 'description' => 'd', 'code' => 'print(1)']) === 'error: the user did not approve this script. Nothing was saved.');
    $sapp->cfg->autoApprove[] = 'script_save';
    check('scripts: saved when approved ahead', $sx('script_save', ['name' => 'Add', 'description' => 'd', 'code' => 'print(1)']) === "saved script 'add': run it with script_run name=add.");
    $sapp->cfg->autoApprove = array_values(array_diff($sapp->cfg->autoApprove, ['script_save']));
    check('scripts: replacing asks again', $sx('script_save', ['name' => 'Add', 'description' => 'd', 'code' => 'print(2)']) === 'error: the user did not approve this script. Nothing was saved.');
    check('scripts: an unknown script lists yours', $sx('script_run', ['name' => 'nope']) === "error: no script named 'nope'. Yours: add");
    file_put_contents($sapp->scripts()->dir() . '/add.py', "\nprint('changed')\n", FILE_APPEND);
    check('scripts: changed code waits for the user', $sx('script_run', ['name' => 'add']) === 'error: the user did not approve this script. Nothing was run.');
    App::$inWorker = true;
    check('scripts: changed code cannot wait in the worker', str_contains($sx('script_run', ['name' => 'add']), 'changed since the user approved it'));
    App::$inWorker = false;
    check('scripts: script_read of an unknown script', $sx('script_read', ['name' => 'nope']) === "error: no script named 'nope'");
    check('scripts: script_delete of an unknown script', $sx('script_delete', ['name' => 'nope']) === "error: no script named 'nope'");
    check('shell: shell_save of a missing file is an error', str_starts_with($sx('shell_save', ['path' => '/work/none.txt']), 'error: '));
    $sapp->space = 0;
    check('agent: the job prompt lists saved scripts', str_contains($sapp->agent()->systemPrompt('job'), "## Saved scripts (run with script_run)\n- add"));
    check('agent: the prompt says commands wait for approval in "ask" mode', str_contains($sapp->agent()->systemPrompt(), "Each command waits for the user's approval"));
}
// saveLogin: what it checks before reading the browser's cookies
$saveLogin = fn(string $dom, string $path) => (fn() => $this->saveLogin($dom, $path))->call($app->tools);
check('tools: saveLogin refuses a relative path', str_starts_with($saveLogin('acme.example', 'cookies.json'), 'error: give the full path'));
file_put_contents("$d/tl/notcookies.json", '{"name":"Alex"}');
check('tools: saveLogin keeps a file that is not a cookie file', $saveLogin('acme.example', "$d/tl/notcookies.json") === "error: $d/tl/notcookies.json exists and is not a cookie file; nothing was written");

// more tool paths
$app->space = (int)$spA['id'];
check('tools: board_invite without guests is an error', str_starts_with($x('board_invite', ['code' => $codeA]), 'error: '), $x('board_invite', ['code' => $codeA]));
$r = $x('board_list');
check('tools: board_list in a space groups by column', str_starts_with($r, '## ') && str_contains($r, $codeA) && !str_contains($r, 'all spaces'), $r);
$r = $x('board_add', ['title' => 'Workshop', 'start' => '2026-11-02', 'due' => '2026-11-30', 'start_time' => '10:00']);
check('tools: a timed task cannot last weeks', str_starts_with($r, 'error: A task with a time is one slot: 2026-11-02 10:00 to 2026-11-30'), $r);
$x('board_add', ['title' => 'Quick call']);
$codeC = array_values(array_filter($app->board()->list(['space' => (int)$spA['id']]), fn($c) => $c['title'] === 'Quick call'))[0]['code'];
$r = $x('board_log_time', ['code' => $codeC, 'time' => '45m', 'note' => 'call']);
check('tools: board_log_time without an estimate', $r === "logged on $codeC: " . \Talixio\Board::fmtHours(0.75) . ' spent in total', $r);
$app->space = 0;
check('tools: skill_delete of an unknown skill', $x('skill_delete', ['name' => 'nope']) === 'error: no such skill');
$x('schedule_task', ['title' => 'Weekly Acme check', 'prompt' => 'Check Acme', 'when' => 'every monday at 8:00']);
$tid = (int)$app->store->q('SELECT MAX(id) FROM tasks')->fetchColumn();
check('tools: cancel_task deletes a routine', $tid > 0 && $x('cancel_task', ['id' => $tid]) === 'deleted');
preg_match('/^job #(\d+)/', $x('delegate', ['title' => 'To cancel', 'instructions' => 'x']), $jm2);
check('tools: cancel_job of a queued job', $x('cancel_job', ['id' => (int)$jm2[1]]) === 'cancelled');
$r = $x('continue_job', ['id' => $j1]);
check('tools: continue_job keeps the earlier job\'s cap and effort', str_contains($r, '(cap $0.50, effort low)'), $r);
$app->spaces()->save(['archived' => 1] + $app->spaces()->get((int)$spB['id']), (int)$spB['id'], 'assistant');
$r = $x('spaces', ['action' => 'list']);
check('tools: spaces list marks the archived ones', str_contains($r, '- Beta [archived'), $r);
$r = $x('spaces', ['action' => 'history', 'space' => 'Beta']);
check('tools: spaces history says who changed what', str_contains($r, ': archived') && str_contains($r, '— from the assistant'), $r);
check('tools: spaces restore of a space that is not archived', $x('spaces', ['action' => 'restore', 'space' => 'Acme']) === 'Acme is not archived.');
check('tools: spaces of an unknown space', str_starts_with($x('spaces', ['action' => 'history', 'space' => 'Nowhere']), 'error: no such space'));
// connectors are offered by their server's name
$ft = $T->forAnswer();
$ft->register('mcp_acmecrm_find', '[acmecrm] Find a customer in Acme CRM', ['q' => ['type' => 'string']], [], fn() => 'Alex at Acme');
$fo = $ft->focus('hello');
check('tools: focus offers a connector by its server name', !in_array('mcp_acmecrm_find', array_column($fo->schemas(), 'name'), true) && str_contains((fn() => $this->tools['tools_more'][0]['description'])->call($fo), 'acmecrm: the acmecrm connector'));
check('tools: tools_more adds a connector by its server name', $fo->execute('tools_more', ['groups' => ['acmecrm']]) === 'now available: mcp_acmecrm_find');
check('tools: focus shows a connector named in the message', in_array('mcp_acmecrm_find', array_column($ft->focus('look Alex up in AcmeCRM')->schemas(), 'name'), true));
// a link the user gave may be fetched after untrusted content
Privacy::$untrusted = true; Privacy::$links = ['http://127.0.0.1/acme' => true];
$r = $T->run('fetch_url', ['url' => 'http://127.0.0.1/acme/']);
check('tools: a known link is fetched without asking, still under the address rules', str_starts_with($r, 'error: ') && !str_contains($r, 'Waiting'), $r);
Privacy::trustAgain();
$ct = \Closure::bind(fn(array $a) => Tools::calendarTimes($a), null, Tools::class);
check('tools: calendar_add times, all day with an end day', $ct(['start' => '2026-11-02', 'end' => '2026-11-03']) === [strtotime('2026-11-02'), true, strtotime('2026-11-04')]);
check('tools: calendar_add times, an hour by default', $ct(['start' => '2026-11-02 10:00']) === [strtotime('2026-11-02 10:00'), false, strtotime('2026-11-02 11:00')]);
check('tools: calendar_add times, a day by default', $ct(['start' => '2026-11-02', 'end' => '']) === [strtotime('2026-11-02'), true, strtotime('2026-11-03')]);
if (\Talixio\Shell::binary()) {
    $sapp->cfg->shellNetwork = true;
    $sapp->cfg->approvalWaitSeconds = 0;
    $sapp->cfg->autoApprove[] = 'script_save';
    check('scripts: delete a script', $sx('script_save', ['name' => 'Gone', 'description' => 'd', 'code' => 'print(1)']) !== '' && $sx('script_delete', ['name' => 'gone']) === 'deleted');
    $aid = (int)$sapp->store->q('SELECT COALESCE(MAX(id),0) FROM approvals')->fetchColumn();
    $sx('shell_run', ['command' => 'curl acme.example']);
    check('shell: the approval says the sandbox has internet', str_contains((string)$sapp->store->q('SELECT summary FROM approvals WHERE id > ? ORDER BY id DESC LIMIT 1', [$aid])->fetchColumn(), 'Run in the sandbox (with internet)'));
}

// ---------- Agent ----------
$aapp = $mkApp('ag', ['TALIXIO_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => 'sk-test-not-a-key']);
$aapp->cfg->budgetDaily = 0; $aapp->cfg->budgetMonthly = 0; $aapp->cfg->approvalWaitSeconds = 0; $aapp->cfg->learn = false;
$afm = new FakeModel();
$anext = fn() => 'Done.';
$afm->script = function (...$a) use (&$anext) { return $anext(...$a); };
$aapp->providerOverride = $afm;
$ag = $aapp->agent();
$shownTools = [];
// focused tools: the last messages pick the groups, which stay for the next answers
$aapp->cfg->tools = 'focused';
$anext = function ($last, $tools) use (&$shownTools) { $shownTools = array_column($tools->schemas(), 'name'); return 'Here is the board.'; };
$ag->chat('web', 'show my task board');
check('agent: focused tools follow the message', in_array('board_list', $shownTools, true) && !in_array('mail_send', $shownTools, true), json_encode($shownTools));
check('agent: the groups given are kept for the space', array_key_exists('tasks', json_decode((string)$aapp->store->get('tool_groups_0', '{}'), true)));
$ag->chat('web', 'thanks');
check('agent: kept groups stay in the next answer', in_array('board_list', $shownTools, true));
$fid = $aapp->files()->save(tap_tmp("Acme invoice 42\nTotal 100 EUR"), 'invoice.txt', 'text/plain', '/Inbox');
$ag->chat('web', 'what is this?', 'standard', [$fid]);
check('agent: an attachment brings the library tools', in_array('kb_move', $shownTools, true));
$aapp->cfg->tools = 'all';
// capture without a model call
$calls = count($afm->calls);
$r = $ag->chat('web', 't: Call the Acme accountant !2');
check('agent: "t: …" adds a task without the model', str_starts_with($r, 'Task added: ') && str_contains($r, 'Call the Acme accountant (P2)') && count($afm->calls) === $calls, $r);
$r = $ag->chat('web', 't: !2');
check('agent: a capture that fails says why', str_starts_with($r, "I couldn't answer: "), $r);
// escalation to the deep tier, then after a failed tool
$aapp->cfg->escalate = 'on';
$events = [];
$on = function (array $e) use (&$events) { $events[] = $e; };
$anext = fn() => 'Noted.';
$ag->chat('web', 'remind me every monday at 9:00 to call Alex', 'standard', [], $on);
check('agent: a scheduling request goes to the deep tier', in_array(['type' => 'status', 'text' => 'Using a stronger model…'], $events, true));
$anext = function ($last, $tools) { $tools->run('skill_delete', ['name' => 'nope']); return 'That failed.'; };
$ag->chat('web', 'delete the skill nope');
check('agent: a failed tool marks the space as in trouble', (int)$aapp->store->get('trouble_0', '0') > time() - 60);
$anext = fn() => 'Fine now.';
$ag->chat('web', 'ok');
check('agent: a turn without failures clears the trouble', $aapp->store->get('trouble_0', '') === '0');
$aapp->cfg->escalate = 'off';
// the same plan twice: a stronger model takes over once
$plan = 'I will look up the Acme invoice, compare it with the quote and then tell you the difference.';
$anext = fn() => $plan;
$ag->chat('web', 'check the Acme invoice', 'fast');
$events = [];
$n0 = count($afm->calls);
$ag->chat('web', 'yes go', 'fast', [], $on);
check('agent: a repeated answer is asked again of a stronger model', count($afm->calls) === $n0 + 2 && in_array(['type' => 'status', 'text' => 'Asking a stronger model…'], $events, true)
    && str_contains($afm->calls[$n0 + 1]['context'], 'Your last answer only restated a plan'));
// stopped by the user mid-answer
$anext = function ($last, $tools, $onUsage, $context, $messages, $onEvent) use ($aapp) {
    $onEvent(['type' => 'text', 'text' => 'Partial answer']);
    $onEvent(['type' => 'tool', 'name' => 'kb_search']);
    usleep(550000);
    $aapp->store->set('chat_stop', (string)(microtime(true) + 5));
    $onEvent(['type' => 'text', 'text' => ' more']);
    return 'never';
};
$r = $ag->chat('web', 'long answer please', 'standard', [], fn() => null);
check('agent: the Stop button keeps the partial answer', $r === "Partial answer\n\n*(stopped)*", $r);
$aapp->store->set('chat_stop', '0');
// errors
$anext = function () { throw new RuntimeException('model exploded'); };
check('agent: a model failure is told to the user', $ag->chat('web', 'hello') === "I couldn't answer: model exploded");
$aapp->cfg->provider = 'sovereign'; $aapp->cfg->sovereign = 'mistral'; $aapp->cfg->sovereignUrl = 'http://127.0.0.1:9'; $aapp->cfg->sovereignKey = 'k-test';
$anext = function () { throw new RuntimeException('API error 401: unauthorized'); };
$r = $ag->chat('web', 'hello');
check('agent: a refused European key says how to fix it', str_contains($r, 'refuses its key (HTTP 401)'), $r);
$aapp->cfg->provider = 'anthropic';
$aapp->cfg->sovereignOnly = true;
check('agent: European-only refuses another provider', str_starts_with($ag->chat('web', 'hello'), 'Only European providers may be used'));
$aapp->cfg->sovereignOnly = false;
$aapp->cfg->provider = 'claude-code'; $aapp->cfg->claudeBin = '/nonexistent/claude';
check('agent: no Claude Code installed', str_starts_with($ag->chat('web', 'hello'), 'Claude Code is not installed on this server'));
$aapp->cfg->provider = 'anthropic';
$sec = $aapp->spaces()->save(['name' => 'Vault', 'privacy' => 'secret']);
check('agent: a secret space cannot use an outside model', str_starts_with($ag->chat('web', 'hello', 'standard', [], null, (int)$sec['id']), 'This is a Secret space'));
$aapp->cfg->geminiKey = 'g-test'; $aapp->cfg->provider = 'gemini'; $aapp->cfg->geminiPaid = false;
$conf = $aapp->spaces()->save(['name' => 'Legal', 'privacy' => 'confidential']);
check('agent: a confidential space needs business terms', str_starts_with($ag->chat('web', 'hello', 'standard', [], null, (int)$conf['id']), 'This is a Confidential space'));
$aapp->cfg->provider = 'anthropic';
// the budget runs out during the answer; an unknown model's cost
$anext = function ($last, $tools, $onUsage) use ($usage) { $usage($onUsage, 'mystery-model-x'); return 'ok'; };
[$txt, $meta] = $ag->run('chat', 'fast', 'chat', [['role' => 'user', 'content' => 'hi']], '', 'main', null, function (float $c) use (&$guarded) { $guarded = $c; });
check('agent: an unknown model makes the cost unknown, and the guard sees the cost', $txt === 'ok' && $meta['cost_known'] === false && $guarded === 0.0);
$aapp->cfg->budgetDaily = 0.000001;
$anext = function ($last, $tools, $onUsage) use ($usage) { $usage($onUsage, 'claude-sonnet-4-5', 100000, 100000); return 'ok'; };
check('agent: the budget running out stops the answer', $caught(fn() => $ag->run('chat', 'fast', 'chat', [['role' => 'user', 'content' => 'hi']])) === 'budget exhausted during the run');
$r = $ag->chat('web', 'hello');
check('agent: an exhausted budget is told to the user', str_starts_with($r, "I've reached my spending limit"), $r);
$aapp->cfg->budgetDaily = 0;
$aapp->store->q('DELETE FROM usage');
// heartbeat
$aapp->store->addMessage('user', 'web', 'Is the Acme invoice paid?');
$anext = fn($last) => str_contains($last, '[Heartbeat check') ? 'Alex, the Acme invoice is due today.' : 'x';
check('agent: a heartbeat with something to say sends it', $ag->heartbeat() === 'Alex, the Acme invoice is due today.' && str_contains(end($afm->calls)['messages'][count(end($afm->calls)['messages']) - 1]['content'], 'Is the Acme invoice paid?'));
check('agent: the sent heartbeat is recorded', $aapp->store->q("SELECT outcome FROM heartbeats ORDER BY id DESC LIMIT 1")->fetchColumn() === 'sent');
$anext = function () { throw new Abort('budget exhausted'); };
check('agent: a skipped heartbeat is recorded and rethrown', $caught(fn() => $ag->heartbeat()) === 'budget exhausted' && $aapp->store->q("SELECT outcome FROM heartbeats ORDER BY id DESC LIMIT 1")->fetchColumn() === 'skipped');
$anext = function () { throw new RuntimeException('down'); };
check('agent: a failed heartbeat is recorded and rethrown', $caught(fn() => $ag->heartbeat()) === 'down' && $aapp->store->q("SELECT outcome FROM heartbeats ORDER BY id DESC LIMIT 1")->fetchColumn() === 'error');
// a routine with its feedback, an attachment from a chat app, the prompt variants
$anext = fn() => 'Weekly Acme summary.';
$aapp->tools->execute('schedule_task', ['title' => 'Acme weekly', 'prompt' => 'Summarise Acme', 'when' => 'every monday at 8:00']);
$rtid = (int)$aapp->store->q('SELECT MAX(id) FROM tasks')->fetchColumn();
$aapp->routines()->vote($rtid, 'weather', -1, 'not useful');
check('agent: a routine run carries its feedback', $ag->runTask('Acme weekly', 'Summarise Acme', 'bogus', 0, $rtid) === 'Weekly Acme summary.' && str_contains(end($afm->calls)['messages'][count(end($afm->calls)['messages']) - 1]['content'] ?? '', 'weather'));
check('agent: a chat-app message with a document', $ag->incoming('telegram', 'see attached', [$fid]) === 'Weekly Acme summary.');
$solo = $mkApp('agsolo');
$one = $solo->spaces()->save(['name' => 'Solo']);
$solo->space = (int)$one['id'];
check('agent: a space alone lists only General as the other one', str_contains($p = $solo->agent()->systemPrompt(), '## Current space: Solo') && str_contains($p, 'kept separate from the others (General). '));
$solo->cfg->lang = 'fr';
check('agent: in French the prompt expects French', str_contains($solo->agent()->systemPrompt(), ' (usually French)'));
check('agent: a question on which connectors exist gets the list', str_contains($ag->faqFor('which MCP connectors are available?'), 'connectors known to work'));

// /space commands from a chat app
$conf2 = $aapp->spaces()->find('Legal');
check('agent: "/space" alone lists the spaces', str_starts_with($ag->chat('signal', '/space'), 'Spaces: ') && str_contains($ag->chat('signal', '/space'), 'General (current)'));
check('agent: "/space" with an unknown name', str_starts_with($ag->chat('signal', '/space Nowhere'), 'No space called "Nowhere"'));
check('agent: Telegram cannot enter a confidential space', str_contains($ag->chat('telegram', '/space Legal'), 'is a Confidential space: Telegram can read'));
check('agent: "/space Legal" switches a chat app', $ag->chat('signal', '/space Legal') === 'Now in the space "Legal". "/space general" to come back.' && $aapp->spaces()->forChannel('signal') === (int)$conf2['id']);
$aapp->spaces()->setForChannel('signal', 0);
// the cost calibration question, a missing key, the heartbeat staying quiet
$aapp->store->q('INSERT INTO usage(ts,model,input_tokens,output_tokens,cost_usd,source) VALUES(?,?,?,?,?,?)', [strtotime('first day of last month 12:00'), 'claude-sonnet-4-5', 10, 10, 1.25, 'chat']);
$anext = fn() => 'Sure.';
$ag->chat('web', 'hello again');
check('agent: once a month the turn asks for the real bill', str_contains(end($afm->calls)['context'], 'Cost calibration: after answering') && (int)$aapp->store->get('bill_asked', '0') > 0);
$aapp->store->q('DELETE FROM usage');
$aapp->cfg->anthropicKey = '';
check('agent: no key says how to set one up', str_starts_with($ag->chat('web', 'hello'), 'No AI model is set up yet'));
$aapp->cfg->anthropicKey = 'sk-test-not-a-key';
$anext = fn() => 'HEARTBEAT_OK - nothing due for Acme.';
check('agent: a quiet heartbeat sends nothing and keeps its note', $ag->heartbeat() === null && $aapp->store->heartbeats(1)[0]['outcome'] === 'quiet' && $aapp->store->heartbeats(1)[0]['note'] === 'nothing due for Acme.');
$anext = fn() => '';
check('agent: an empty heartbeat is quiet too', $ag->heartbeat() === null && $aapp->store->heartbeats(1)[0]['note'] === 'Nothing needs attention.');
// a repeated answer on the deep tier has no stronger model
$anext = fn() => $plan;
$ag->chat('web', 'check it again', 'deep');
$n0 = count($afm->calls);
$ag->chat('web', 'go on', 'deep');
check('agent: on the deep tier a repeated answer is kept', count($afm->calls) === $n0 + 1);
// a scheduled task without a routine id, attachments with progress events
$anext = fn() => 'Reminder sent.';
check('agent: a one-off scheduled task runs', $ag->runTask('Call Alex', 'Remind me to call Alex', 'fast') === 'Reminder sent.' && !str_contains(end($afm->calls)['messages'][count(end($afm->calls)['messages']) - 1]['content'], 'feedback'));
$events = [];
$ag->chat('web', 'file this', 'standard', [$fid], $on);
check('agent: attachments are announced while read', in_array(['type' => 'status', 'text' => 'Reading the attachments…'], $events, true));
check('agent: a French question on connectors gets the French FAQ', str_contains($ag->faqFor('quels connecteurs existent ?'), 'Français/Connecteurs/'));
check('agent: a connector install from GitHub skips the list', !str_contains($ag->faqFor('install the mcp connector https://github.com/acme/mcp'), 'connectors known to work'));
check('agent: a question not about connectors adds no FAQ', $ag->faqFor('what is the weather?') === '');

// ---------- Tools: a few more outcomes on a fresh app ----------
$t2 = $mkApp('tl2');
$t2->cfg->approvalWaitSeconds = 0;
$x2 = fn(string $n, array $a = []) => $t2->tools->execute($n, $a);
check('tools: calendar_add times, a timed end', $ct(['start' => '2026-11-02 10:00', 'end' => '2026-11-02 12:30']) === [strtotime('2026-11-02 10:00'), false, strtotime('2026-11-02 12:30')]);
check('tools: board_changes with nothing done yet', $x2('board_changes') === 'no changes yet');
check('tools: list_jobs archived with a search finds none', $x2('list_jobs', ['archived' => true, 'search' => 'acme']) === 'no archived jobs matching "acme" in this space');
check('tools: usage_report with no usage says nothing yet', substr_count($x2('usage_report'), '  nothing yet') === 3);
$t2->store->addUsage(['model' => 'claude-sonnet-4-5', 'in' => 1000, 'out' => 500], 0.0105, 'chat', 'standard', null);
check('tools: usage_report shows a known cost', str_contains($x2('usage_report'), '1 calls, 1,000 in / 500 out tokens, $0.0105'));
$r = $x2('usage_bill', ['amount' => 5, 'currency' => 'USD', 'month' => date('Y-m', strtotime('first day of last month'))]);
check('tools: usage_bill for a month with too little to compare', str_contains($r, 'recorded: USD 5.00') && str_contains($r, '(too little to compare)'), $r);
$t2->store->q('INSERT INTO usage(ts,model,input_tokens,output_tokens,cost_usd,source) VALUES(?,?,?,?,?,?)', [strtotime('first day of last month 12:00'), 'claude-sonnet-4-5', 10, 10, 2.5, 'chat']);
$r = $x2('usage_bill', ['amount' => 5, 'currency' => 'USD', 'month' => date('Y-m', strtotime('first day of last month'))]);
check('tools: usage_bill compares with the estimate', str_contains($r, 'had estimated $2.50 (ratio 2.00)'), $r);
$t2->skills()->save('acme-invoices', 'How to file Acme invoices', "1. Find the invoice\n2. File it");
check('tools: skill_delete removes a skill', $x2('skill_delete', ['name' => 'acme-invoices']) === 'deleted' && $x2('skill_delete', ['name' => 'acme-invoices']) === 'error: no such skill');
$x2('board_add', ['title' => 'Prepare the Acme quote']);
$x2('board_add', ['title' => 'Send the Acme quote']);
$bl = $t2->board()->list([]);
$codes = array_column($bl, 'code', 'title');
$x2('board_update', ['code' => $codes['Send the Acme quote'], 'add_depends_on' => [$codes['Prepare the Acme quote']]]);
$r = $x2('board_delete', ['code' => $codes['Prepare the Acme quote']]);
check('tools: board_delete names the tasks that were waiting', $r === "{$codes['Prepare the Acme quote']} moved to the trash. These tasks were waiting for it: {$codes['Send the Acme quote']}", $r);
check('tools: board_delete without waiting tasks', $x2('board_delete', ['code' => $codes['Send the Acme quote']]) === "{$codes['Send the Acme quote']} moved to the trash");
App::$inWorker = true; $t2->jobId = 7;
check('tools: the sandbox environment says where it runs', $t2->tools->env() === ['TALIXIO_SPACE' => '0', 'TALIXIO_IN_WORKER' => '1', 'TALIXIO_JOB' => '7']);
App::$inWorker = $inWorkerSaved; $t2->jobId = null;

// ---------- end: restore global state ----------
Http::$hook = $hookSaved;
App::$inWorker = $inWorkerSaved;
Privacy::trustAgain();
Privacy::$purpose = 'model'; Privacy::$allowOnce = false; Privacy::$taint = 0; Privacy::$trace = null; Privacy::$onEvent = null;
foreach ($envSaved as $k => $v) putenv($v === false ? $k : "$k=$v");

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
