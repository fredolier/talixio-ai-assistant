<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Uses the official Claude Code CLI in headless mode (`claude -p`), so a Claude Pro/Max
 * subscription can power the assistant. Talixio's own tools are exposed to it through
 * `talixio mcp` (an MCP server); Claude Code's built-in WebSearch/WebFetch are also allowed.
 * Shell and file-editing tools stay disabled.
 */
final class ClaudeCodeProvider implements Provider
{
    public function __construct(private string $bin, private string $model, private bool $webSearch, private string $talixioBin) {}

    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string
    {
        $last = array_pop($messages);
        $transcript = '';
        foreach ($messages as $m) {
            $transcript .= ($m['role'] === 'user' ? 'User' : 'Assistant') . ":\n" . $m['content'] . "\n\n";
        }
        if ($transcript !== '') $system .= "\n\n# Conversation so far\n\n" . $transcript;

        $mcp = tempnam(sys_get_temp_dir(), 'talixio-mcp');
        file_put_contents($mcp, json_encode(['mcpServers' => ['talixio' => [
            'command' => PHP_BINARY, 'args' => [$this->talixioBin, 'mcp'],
        ]]], JSON_UNESCAPED_SLASHES));
        $allowed = 'mcp__talixio__*' . ($this->webSearch ? ',WebSearch,WebFetch' : '');
        $cmd = [$this->bin, '-p', '--output-format', 'json', '--max-turns', '15',
            '--mcp-config', $mcp, '--strict-mcp-config',
            '--allowedTools', $allowed,
            '--disallowedTools', 'Bash,Edit,Write,MultiEdit,NotebookEdit,Task',
            '--append-system-prompt', $system];
        if ($this->model !== '') array_push($cmd, '--model', $this->model);

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
        if (!is_resource($proc)) { @unlink($mcp); throw new \RuntimeException("cannot run {$this->bin}"); }
        fwrite($pipes[0], $last['content'] ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        @unlink($mcp);

        $r = json_decode((string)$out, true);
        if (!is_array($r)) throw new \RuntimeException("claude exited $code: " . mb_substr(trim($err ?: (string)$out), 0, 400));
        $onUsage('claude-code' . ($this->model ? ":{$this->model}" : ''),
            (int)($r['usage']['input_tokens'] ?? 0) + (int)($r['usage']['cache_read_input_tokens'] ?? 0) + (int)($r['usage']['cache_creation_input_tokens'] ?? 0),
            (int)($r['usage']['output_tokens'] ?? 0), isset($r['total_cost_usd']) ? (float)$r['total_cost_usd'] : null);
        if (!empty($r['is_error'])) throw new \RuntimeException('claude: ' . ($r['result'] ?? $r['subtype'] ?? 'error'));
        return (string)($r['result'] ?? '');
    }
}
