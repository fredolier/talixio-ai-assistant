<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Uses the official Claude Code CLI in headless mode (`claude -p`), so a Claude Pro/Max
 * subscription can power the assistant. Talixio's tools are exposed through `talixio mcp`
 * (only the ones in the given toolset are allowed); Claude Code's WebSearch/WebFetch are
 * allowed when search is on. Shell, file editing and Claude Code's own sub-agents stay off:
 * Talixio runs its own sub-agents with budgets.
 */
final class ClaudeCodeProvider implements Provider
{
    public function __construct(private string $bin, private Tier $tier, private bool $webSearch, private string $talixioBin) {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage): string
    {
        $last = array_pop($messages);
        $transcript = '';
        foreach ($messages as $m) {
            $transcript .= ($m['role'] === 'user' ? 'User' : 'Assistant') . ":\n" . $m['content'] . "\n\n";
        }
        $prompt = trim("$system\n\n$context") . ($transcript !== '' ? "\n\n# Conversation so far\n\n$transcript" : '');

        $allowed = array_map(fn($n) => "mcp__talixio__$n", $tools->names());
        if ($this->webSearch && $tools->allowsWeb()) array_push($allowed, 'WebSearch', 'WebFetch');
        $cmd = [$this->bin, '-p', '--output-format', 'json', '--max-turns', (string)$this->tier->maxSteps,
            '--disallowedTools', 'Bash,Edit,Write,MultiEdit,NotebookEdit,Task,Agent',
            '--append-system-prompt', $prompt];
        $mcp = null;
        if ($tools->names()) {
            $mcp = tempnam(sys_get_temp_dir(), 'talixio-mcp');
            file_put_contents($mcp, json_encode(['mcpServers' => ['talixio' => ['command' => PHP_BINARY, 'args' => [$this->talixioBin, 'mcp']]]], JSON_UNESCAPED_SLASHES));
            array_push($cmd, '--mcp-config', $mcp, '--strict-mcp-config');
        }
        if ($allowed) array_push($cmd, '--allowedTools', implode(',', $allowed));
        if ($this->tier->model !== '') array_push($cmd, '--model', $this->tier->model);

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
        if (!is_resource($proc)) { if ($mcp) @unlink($mcp); throw new \RuntimeException("cannot run {$this->bin}"); }
        fwrite($pipes[0], $last['content'] ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($mcp) @unlink($mcp);

        $r = json_decode((string)$out, true);
        if (!is_array($r)) throw new \RuntimeException("claude exited $code: " . mb_substr(trim($err ?: (string)$out), 0, 400));
        $u = $r['usage'] ?? [];
        $onUsage(['model' => 'claude-code:' . ($this->tier->model ?: 'default'), 'in' => (int)($u['input_tokens'] ?? 0),
            'out' => (int)($u['output_tokens'] ?? 0), 'cache_read' => (int)($u['cache_read_input_tokens'] ?? 0),
            'cache_write' => (int)($u['cache_creation_input_tokens'] ?? 0),
            'cost' => isset($r['total_cost_usd']) ? (float)$r['total_cost_usd'] : null]);
        if (!empty($r['is_error'])) throw new \RuntimeException('claude: ' . ($r['result'] ?? $r['subtype'] ?? 'error'));
        return (string)($r['result'] ?? '');
    }
}
