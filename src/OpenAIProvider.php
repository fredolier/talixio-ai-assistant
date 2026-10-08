<?php
declare(strict_types=1);
namespace Talixio;

/** OpenAI, or any OpenAI-compatible server (Ollama, vLLM, OpenRouter...) via the base URL. */
final class OpenAIProvider implements Provider
{
    public function __construct(private string $key, private Tier $tier, private string $baseUrl) {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage): string
    {
        $msgs = array_merge([['role' => 'system', 'content' => trim("$system\n\n$context")]], $messages);
        $payload = ['model' => $this->tier->model];
        if ($specs = array_map(fn($t) => ['type' => 'function', 'function' => $t], $tools->schemas())) $payload['tools'] = $specs;
        if ($this->tier->reasoningEffort !== '') $payload['reasoning_effort'] = $this->tier->reasoningEffort;
        for ($i = 0; $i < $this->tier->maxSteps; $i++) {
            $r = Http::json("{$this->baseUrl}/chat/completions", $payload + ['messages' => $msgs], ['Authorization: Bearer ' . ($this->key ?: 'none')], 300);
            $cached = (int)($r['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
            $onUsage(['model' => $this->tier->model, 'in' => (int)($r['usage']['prompt_tokens'] ?? 0) - $cached,
                'out' => (int)($r['usage']['completion_tokens'] ?? 0), 'cache_read' => $cached, 'cache_write' => 0, 'cost' => null]);
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
