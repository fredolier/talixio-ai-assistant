<?php
declare(strict_types=1);
namespace Talixio;

/** OpenAI, or any OpenAI-compatible server (Ollama, vLLM, OpenRouter...) via the base URL. */
final class OpenAIProvider implements Provider
{
    public function __construct(private string $key, private string $model, private string $baseUrl) {}

    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string
    {
        $msgs = array_merge([['role' => 'system', 'content' => $system]], $messages);
        $specs = array_map(fn($t) => ['type' => 'function', 'function' => $t], $tools->schemas());
        for ($i = 0; $i < 12; $i++) {
            $r = Http::json("{$this->baseUrl}/chat/completions",
                ['model' => $this->model, 'messages' => $msgs, 'tools' => $specs],
                ['Authorization: Bearer ' . ($this->key ?: 'none')]);
            $onUsage($this->model, (int)($r['usage']['prompt_tokens'] ?? 0), (int)($r['usage']['completion_tokens'] ?? 0), null);
            $m = $r['choices'][0]['message'] ?? [];
            if (empty($m['tool_calls'])) return (string)($m['content'] ?? '');
            $msgs[] = $m;
            foreach ($m['tool_calls'] as $c) {
                $args = json_decode($c['function']['arguments'] ?: '{}', true) ?: [];
                $msgs[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => $tools->run($c['function']['name'], $args)];
            }
        }
        return '(stopped after too many tool steps)';
    }
}
