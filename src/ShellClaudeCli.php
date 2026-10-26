<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The B-local conductor runtime (docs/design/orchestration.md §9 slice 4a): a delegated Claude
 * Code session as a **detached background process on Talixio's host**, read back from its log.
 *
 * start() launches `claude -p --output-format stream-json` under `nohup`, so it outlives the
 * worker tick that spawned it, writing its stream to a per-session log file. The handle carries
 * the pid and that log path. poll() reads the log for the final `result` event (result text,
 * cost, error flag); if the process is gone before a result landed, the session crashed. This is
 * the simplest reliable local readback; whole-account cloud reach (B-cloud, the webhook bridge)
 * is a later slice. The CLI runs with the same environment rules as ClaudeCodeProvider — an AI
 * account's subscription token overrides the saved login, billing keys stay with the server.
 */
final class ShellClaudeCli implements ClaudeCli
{
    public function __construct(private string $bin, private string $logDir, private string $token = '') {}

    public function start(string $prompt, Tier $tier, string $cwd): string
    {
        if (!is_dir($this->logDir)) @mkdir($this->logDir, 0700, true);
        if (!is_dir($cwd)) @mkdir($cwd, 0700, true);
        $log = $this->logDir . '/' . bin2hex(random_bytes(8)) . '.jsonl';
        $cmd = [$this->bin, '-p', '--output-format', 'stream-json', '--verbose', '--max-turns', (string)max(1, $tier->maxSteps)];
        if ($tier->reasoningEffort !== '') array_push($cmd, '--effort', $tier->reasoningEffort);
        if ($tier->model !== '') array_push($cmd, '--model', $tier->model);
        $cmd[] = $prompt;
        // nohup exec's claude in place, so $! is the claude pid and it ignores SIGHUP when the tick returns.
        $shell = 'cd ' . escapeshellarg($cwd) . ' && exec nohup ' . implode(' ', array_map('escapeshellarg', $cmd))
            . ' </dev/null >' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pid = (int)trim((string)@shell_exec('/bin/sh -c ' . escapeshellarg($shell) . ' 2>/dev/null'), " \n");
        if ($pid <= 0) return json_encode(['pid' => 0, 'log' => $log, 'error' => "could not start {$this->bin}"]);
        return json_encode(['pid' => $pid, 'log' => $log, 'started' => time()]);
    }

    public function poll(string $handle): array
    {
        $h = json_decode($handle, true);
        if (!is_array($h)) return ['done' => true, 'ok' => false, 'result' => 'bad session handle', 'cost' => 0.0];
        if (!empty($h['error'])) return ['done' => true, 'ok' => false, 'result' => (string)$h['error'], 'cost' => 0.0];
        $raw = is_file($h['log'] ?? '') ? (string)file_get_contents($h['log']) : '';
        $result = null;
        foreach (explode("\n", $raw) as $line) {
            $ev = json_decode(trim($line), true);
            if (is_array($ev) && ($ev['type'] ?? '') === 'result') $result = $ev;
        }
        if ($result !== null) {
            $ok = empty($result['is_error']);
            return ['done' => true, 'ok' => $ok,
                'result' => (string)($result['result'] ?? $result['subtype'] ?? ($ok ? '' : 'error')),
                'cost' => isset($result['total_cost_usd']) ? (float)$result['total_cost_usd'] : 0.0];
        }
        if (!$this->alive((int)($h['pid'] ?? 0))) // process gone, no result event → it crashed
            return ['done' => true, 'ok' => false, 'result' => 'the session ended without a result', 'cost' => 0.0];
        return ['done' => false, 'ok' => false, 'result' => '', 'cost' => 0.0];
    }

    public function stop(string $handle): void
    {
        $h = json_decode($handle, true);
        $pid = is_array($h) ? (int)($h['pid'] ?? 0) : 0;
        if ($pid > 0 && $this->alive($pid)) @posix_kill($pid, 15); // SIGTERM
    }

    private function alive(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) return @posix_kill($pid, 0);
        return is_dir("/proc/$pid");
    }
}
