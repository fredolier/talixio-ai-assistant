<?php
declare(strict_types=1);
// Run: php tests/run.php   (no network, no API key needed)
require __DIR__ . '/../src/autoload.php';
use Talixio\{App, Config, Cron, Http, McpServer, Provider, Store, Tools, Worker};

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fails;
    echo ($ok ? "ok   " : "FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    if (!$ok) $fails++;
}

date_default_timezone_set('Europe/Paris');
$t = strtotime('2026-10-08 10:17:00'); // a Thursday
$fmt = fn(int $ts) => date('D Y-m-d H:i', $ts);
check('cron every weekday 08:00', $fmt(Cron::next('0 8 * * 1-5', $t)) === 'Fri 2026-10-09 08:00', $fmt(Cron::next('0 8 * * 1-5', $t)));
check('cron every 15 min', $fmt(Cron::next('*/15 * * * *', $t)) === 'Thu 2026-10-08 10:30');
check('cron monthly 1st 09:30', $fmt(Cron::next('30 9 1 * *', $t)) === 'Sun 2026-11-01 09:30');
check('cron sunday as 7', $fmt(Cron::next('0 20 * * 7', $t)) === 'Sun 2026-10-11 20:00');
check('cron hour roll-over', $fmt(Cron::next('5 23 * * *', strtotime('2026-10-08 23:30'))) === 'Fri 2026-10-09 23:05');
check('cron list + range', $fmt(Cron::next('0 9,18 * * 6', $t)) === 'Sat 2026-10-10 09:00');
try { Cron::next('61 * * * *', $t); check('cron rejects bad value', false); } catch (\InvalidArgumentException) { check('cron rejects bad value', true); }

// isolated app in a temp dir
$dir = sys_get_temp_dir() . '/talixio-test-' . getmypid();
putenv("TALIXIO_DATA_DIR=$dir"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_TIMEZONE=Europe/Paris');
$app = App::fromEnv();
$s = $app->store;

$s->addMessage('assistant', 'heartbeat', 'orphan');
$s->addMessage('user', 'web', 'a'); $s->addMessage('user', 'telegram', 'b'); $s->addMessage('assistant', 'web', 'c');
$h = $s->history(10);
check('history starts with user and merges same roles', $h === [['role' => 'user', 'content' => "a\n\nb"], ['role' => 'assistant', 'content' => 'c']], json_encode($h));

$tools = $app->tools;
check('memory append', $tools->run('memory_append', ['file' => 'USER.md', 'text' => '- Name: Fred']) === 'appended' && str_contains($app->ws->read('USER.md'), 'Name: Fred'));
check('memory replace', $tools->run('memory_replace', ['file' => 'USER.md', 'old_text' => 'Fred', 'new_text' => 'Frédéric']) === 'replaced');
check('memory rejects other files', str_starts_with($tools->run('memory_read', ['file' => '../../etc/passwd']), 'error'));
check('heartbeat file empty by default', !$app->ws->hasContent('HEARTBEAT.md'));

$r = $tools->run('schedule_task', ['title' => 'Bank', 'prompt' => 'Remind the user to call the bank', 'at' => date('Y-m-d H:i', time() + 3600)]);
check('schedule once', str_starts_with($r, 'scheduled task #1'), $r);
$r = $tools->run('schedule_task', ['title' => 'Brief', 'prompt' => 'Morning briefing', 'cron' => '0 8 * * 1-5']);
check('schedule cron', str_starts_with($r, 'scheduled task #2'), $r);
check('schedule needs one of at/cron', str_starts_with($tools->run('schedule_task', ['title' => 'x', 'prompt' => 'y']), 'error'));
check('schedule refuses the past', str_starts_with($tools->run('schedule_task', ['title' => 'x', 'prompt' => 'y', 'at' => '2020-01-01 10:00']), 'error'));
check('list tasks', str_contains($tools->run('list_tasks', []), '#2 Brief | cron 0 8 * * 1-5'));
check('tool calls are audited', count($s->toolLog()) >= 7);

check('fetch blocks loopback', str_contains($tools->run('fetch_url', ['url' => 'http://127.0.0.1:8080/']), 'blocked'));
check('fetch blocks metadata IP', str_contains($tools->run('fetch_url', ['url' => 'http://169.254.169.254/latest']), 'blocked'));
check('fetch blocks private LAN', str_contains($tools->run('fetch_url', ['url' => 'http://192.168.1.1/']), 'blocked'));
check('fetch blocks file://', str_contains($tools->run('fetch_url', ['url' => 'file:///etc/passwd']), 'only http'));
check('html to text', Http::htmlToText('<html><head><title>x</title></head><body><script>bad()</script><p>Hello&nbsp;<b>world</b></p></body></html>') === "Hello\u{a0}world");

$mcp = new McpServer($tools);
$init = $mcp->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
check('mcp initialize', ($init['result']['serverInfo']['name'] ?? '') === 'talixio');
check('mcp notification has no reply', $mcp->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null);
$list = $mcp->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
check('mcp tools/list', in_array('schedule_task', array_column($list['result']['tools'], 'name'), true));
check('mcp schema encodes empty properties as object', str_contains(json_encode($list), '"name":"list_tasks","description":"List scheduled tasks.","inputSchema":{"type":"object","properties":{}'));
$call = $mcp->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_tasks', 'arguments' => []]]);
check('mcp tools/call', str_contains($call['result']['content'][0]['text'], 'Brief'));

// worker runs a due task with a fake model, records it in the thread, and advances the schedule
$fake = new class implements Provider {
    public array $seen = [];
    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string {
        $this->seen[] = end($messages)['content'];
        $onUsage('fake', 10, 5, null);
        return 'Time to call the bank.';
    }
};
$s->updateTask(1, ['next_run' => time() - 5]);
$s->updateTask(2, ['next_run' => time() - 5]);
$app->providerOverride = $fake;
$worker = new Worker($app);
$worker->tick(time());
$t1 = $s->task(1); $t2 = $s->task(2);
check('one-off task disabled after running', (int)$t1['enabled'] === 0 && $t1['last_run'] !== null);
check('cron task advanced to the future', (int)$t2['next_run'] > time());
check('task prompt reached the model', str_contains($fake->seen[0] ?? '', 'Remind the user to call the bank'));
$last = $s->messagesAfter(0);
check('task reply stored in the thread', end($last)['content'] === 'Time to call the bank.' && end($last)['channel'] === 'schedule');
check('usage recorded', (int)($s->usageByDay()[0]['calls'] ?? 0) === 2);

exec('rm -rf ' . escapeshellarg($dir));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
