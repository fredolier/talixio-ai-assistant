<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Uses the official Claude Code CLI in headless mode (`claude -p`), so a Claude Pro/Max
 * subscription can power the assistant. Talixio's tools are exposed through `talixio mcp`
 * (only the ones in the given toolset are allowed); Claude Code's WebSearch/WebFetch are
 * allowed when search is on, and Read only inside a private temporary folder holding the
 * images attached to this turn. Shell, file editing and Claude Code's own sub-agents stay
 * off: Talixio runs its own sub-agents with budgets.
 */
final class ClaudeCodeProvider implements Provider
{
    public function __construct(private string $bin, private Tier $tier, private bool $webSearch, private string $talixioBin) {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage, ?callable $onEvent = null): string
    {
        $work = sys_get_temp_dir() . '/talixio-cc-' . bin2hex(random_bytes(6));
        mkdir($work, 0700);
        try {
            $last = array_pop($messages);
            $prompt = $this->lastMessage($last['content'] ?? '', $work);
            $transcript = '';
            foreach ($messages as $m) {
                $transcript .= ($m['role'] === 'user' ? 'User' : 'Assistant') . ":\n" . Content::text($m['content']) . "\n\n";
            }
            $sys = trim("$system\n\n$context") . ($transcript !== '' ? "\n\n# Conversation so far\n\n$transcript" : '');

            $allowed = array_map(fn($n) => "mcp__talixio__$n", $tools->names());
            if ($this->webSearch && $tools->allowsWeb()) array_push($allowed, 'WebSearch', 'WebFetch');
            if (glob("$work/*")) $allowed[] = 'Read';
            $cmd = [$this->bin, '-p', '--output-format', 'stream-json', '--verbose', '--max-turns', (string)$this->tier->maxSteps,
                '--disallowedTools', 'Bash,Edit,Write,MultiEdit,NotebookEdit,Task,Agent,Glob,Grep',
                '--append-system-prompt', $sys];
            if ($tools->names()) {
                file_put_contents("$work/.mcp.json", json_encode(['mcpServers' => ['talixio' => ['command' => PHP_BINARY, 'args' => [$this->talixioBin, 'mcp'], 'env' => $tools->env()]]], JSON_UNESCAPED_SLASHES));
                array_push($cmd, '--mcp-config', "$work/.mcp.json", '--strict-mcp-config');
            }
            if ($allowed) array_push($cmd, '--allowedTools', implode(',', $allowed));
            if ($this->tier->reasoningEffort !== '') array_push($cmd, '--effort', $this->tier->reasoningEffort);
            if ($this->tier->model !== '') array_push($cmd, '--model', $this->tier->model);

            $errFile = "$work/.stderr";
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $work);
            if (!is_resource($proc)) throw new \RuntimeException("cannot run {$this->bin}");
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);
            $result = null; $raw = '';
            try {
                while (($line = fgets($pipes[1])) !== false) {
                    $raw .= $line;
                    $ev = json_decode($line, true);
                    if (!is_array($ev)) continue;
                    if (($ev['type'] ?? '') === 'assistant' && $onEvent) {
                        foreach ($ev['message']['content'] ?? [] as $b) {
                            if (($b['type'] ?? '') === 'tool_use') $onEvent(['type' => 'tool', 'name' => preg_replace('/^mcp__talixio__/', '', $b['name']), 'input' => (array)($b['input'] ?? [])]);
                            elseif (($b['type'] ?? '') === 'text' && $b['text'] !== '') $onEvent(['type' => 'text', 'text' => $b['text']]);
                        }
                    }
                    if (($ev['type'] ?? '') === 'result') $result = $ev;
                }
            } catch (\Throwable $e) { // stopped by the user: end the claude process too
                proc_terminate($proc); proc_close($proc);
                throw $e;
            }
            $code = proc_close($proc);
            if (!$result) $result = json_decode(trim($raw), true); // plain json output (older CLIs or fakes)
            if (!is_array($result)) throw new \RuntimeException("claude exited $code: " . mb_substr(trim((string)@file_get_contents($errFile) ?: $raw), 0, 400));
            $u = $result['usage'] ?? [];
            $onUsage(['model' => 'claude-code:' . ($this->tier->model ?: 'default'), 'in' => (int)($u['input_tokens'] ?? 0),
                'out' => (int)($u['output_tokens'] ?? 0), 'cache_read' => (int)($u['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int)($u['cache_creation_input_tokens'] ?? 0),
                'cost' => isset($result['total_cost_usd']) ? (float)$result['total_cost_usd'] : null]);
            if (!empty($result['is_error'])) throw new \RuntimeException('claude: ' . ($result['result'] ?? $result['subtype'] ?? 'error'));
            return (string)($result['result'] ?? '');
        } finally {
            foreach (glob("$work/{,.}*", GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($work);
        }
    }

    /** Text of the new message; attached images are written next to Claude Code and referenced by path. */
    private function lastMessage(string|array $content, string $work): string
    {
        if (is_string($content)) return $content;
        $out = []; $n = 0;
        foreach ($content as $p) {
            if ($p['type'] === 'image') {
                $ext = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$p['mime']] ?? 'jpg';
                $file = 'image-' . (++$n) . ".$ext";
                file_put_contents("$work/$file", base64_decode($p['data']));
                $out[] = "[Image attached: ./$file — open it with the Read tool to see it]";
            } else {
                $out[] = $p['text'];
            }
        }
        return implode("\n\n", $out);
    }
}
