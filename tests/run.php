<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
use Talixio\{Agent, Config, Memory, Provider};

$fake = new class implements Provider {
    public array $systems = [];
    public function complete(string $system, array $messages, array $tools, callable $runTool): string {
        $this->systems[] = $system;
        $last = end($messages)['content'];
        if (str_starts_with($last, 'my name')) $runTool('remember', ['text' => 'user is Fred']);
        return "echo: $last";
    }
};
$fails = 0;
function check(string $n, bool $ok): void { global $fails; echo ($ok ? 'ok   ' : 'FAIL ') . "$n\n"; if (!$ok) $fails++; }

$agent = new Agent(new Config(), $fake, new Memory(':memory:'));
check('reply', $agent->reply('c1', 'hi') === 'echo: hi');
check('history', array_column($agent->memory->history('c1', 5), 'role') === ['user', 'assistant']);
check('chat isolation', $agent->memory->history('c2', 5) === []);
$agent->reply('c1', 'my name is Fred'); $agent->reply('c1', 'again');
check('facts in prompt', str_contains(end($fake->systems), 'user is Fred'));
check('forget', $agent->tools->run('c1', 'forget', ['text' => 'FRED']) === 'deleted 1');
check('unknown tool', str_starts_with($agent->tools->run('c', 'nope', []), 'error'));
check('schema json', str_contains(json_encode($agent->tools->schemas()), '"properties":{}'));
putenv('TALIXIO_DAILY_JOBS=08:00|42|Morning;;20:30|42|Evening');
check('jobs', Config::fromEnv()->dailyJobs[1] === ['20:30', 42, 'Evening']);
exit($fails ? 1 : 0);
