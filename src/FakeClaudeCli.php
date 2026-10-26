<?php
declare(strict_types=1);
namespace Talixio;

/**
 * An in-memory Claude Code session runner for tests: start() records a session and returns a
 * handle; a test then drives it with finish()/fail() and poll() reflects that. No process is
 * launched, so the conductor's dispatch/poll/finalise path can be exercised deterministically.
 */
final class FakeClaudeCli implements ClaudeCli
{
    /** @var array<string,array{prompt:string,model:string,cwd:string,done:bool,ok:bool,result:string,cost:float,stopped:bool}> */
    public array $sessions = [];
    private int $seq = 0;

    public function start(string $prompt, Tier $tier, string $cwd): string
    {
        $h = 'fake-' . (++$this->seq);
        $this->sessions[$h] = ['prompt' => $prompt, 'model' => $tier->label(), 'cwd' => $cwd,
            'done' => false, 'ok' => false, 'result' => '', 'cost' => 0.0, 'stopped' => false];
        return $h;
    }

    public function poll(string $handle): array
    {
        $s = $this->sessions[$handle] ?? ['done' => true, 'ok' => false, 'result' => 'unknown session', 'cost' => 0.0];
        return ['done' => $s['done'], 'ok' => $s['ok'], 'result' => $s['result'], 'cost' => $s['cost']];
    }

    public function stop(string $handle): void
    {
        if (isset($this->sessions[$handle])) $this->sessions[$handle]['stopped'] = true;
    }

    /** Test helper: mark a session finished with a result and cost. */
    public function finish(string $handle, string $result, float $cost = 0.01): void
    {
        $this->sessions[$handle]['done'] = true; $this->sessions[$handle]['ok'] = true;
        $this->sessions[$handle]['result'] = $result; $this->sessions[$handle]['cost'] = $cost;
    }

    /** Test helper: mark a session failed. */
    public function fail(string $handle, string $why): void
    {
        $this->sessions[$handle]['done'] = true; $this->sessions[$handle]['ok'] = false;
        $this->sessions[$handle]['result'] = $why;
    }
}
