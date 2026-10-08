<?php
declare(strict_types=1);
namespace Talixio;

/** Works with OpenAI and any OpenAI-compatible server (Ollama, vLLM...) via baseUrl. */
final class OpenAIProvider implements Provider
{
    public function __construct(private string $key, private string $model, private string $baseUrl) {}

    public function complete(string $system, array $messages, array $tools, callable $runTool): string
    {
        $msgs = array_merge([['role' => 'system', 'content' => $system]], $messages);
        $specs = array_map(fn($t) => ['type' => 'function', 'function' => $t], $tools);
        for ($i = 0; $i < 8; $i++) {
            $r = Http::json("{$this->baseUrl}/chat/completions",
                ['model' => $this->model, 'messages' => $msgs, 'tools' => $specs],
                ['Authorization: Bearer ' . ($this->key ?: 'none')]);
            $m = $r['choices'][0]['message'] ?? [];
            if (empty($m['tool_calls'])) return (string)($m['content'] ?? '');
            $msgs[] = $m;
            foreach ($m['tool_calls'] as $c) {
                $args = json_decode($c['function']['arguments'] ?: '{}', true) ?: [];
                $msgs[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => $runTool($c['function']['name'], $args)];
            }
        }
        return '(stopped: too many tool steps)';
    }
}
