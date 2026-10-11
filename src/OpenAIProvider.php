<?php
declare(strict_types=1);
namespace Talixio;

/** OpenAI, Gemini, or any OpenAI-compatible server (Ollama, vLLM, OpenRouter...) via the base URL. */
final class OpenAIProvider implements Provider
{
    /** @param bool $plainStream the server refuses stream_options (Mistral): usage still comes in the last chunk */
    public function __construct(private string $key, private Tier $tier, private string $baseUrl, private bool $plainStream = false) {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage, ?callable $onEvent = null): string
    {
        $msgs = array_merge([['role' => 'system', 'content' => trim("$system\n\n$context")]], Content::messages($messages, [Content::class, 'openai']));
        $payload = ['model' => $this->tier->model];
        if ($specs = array_map(fn($t) => ['type' => 'function', 'function' => $t], $tools->schemas())) $payload['tools'] = $specs;
        if ($this->tier->reasoningEffort !== '') $payload['reasoning_effort'] = in_array($this->tier->reasoningEffort, ['xhigh', 'max'], true) ? 'high' : $this->tier->reasoningEffort;
        for ($i = 0; $i < $this->tier->maxSteps; $i++) {
            $r = $onEvent ? $this->stream($payload + ['messages' => $msgs], $onEvent)
                : Http::json("{$this->baseUrl}/chat/completions", $payload + ['messages' => $msgs], ['Authorization: Bearer ' . ($this->key ?: 'none')], 300);
            $m = $r['choices'][0]['message'] ?? [];
            $usage = $r['usage'] ?? null;
            if ($usage === null) { // some compatible servers don't report usage when streaming: estimate (~4 chars per token)
                $usage = ['prompt_tokens' => (int)(strlen(json_encode($msgs)) / 4), 'completion_tokens' => (int)(strlen(json_encode($m)) / 4)];
            }
            $cached = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            $onUsage(['model' => $this->tier->model, 'in' => (int)($usage['prompt_tokens'] ?? 0) - $cached,
                'out' => (int)($usage['completion_tokens'] ?? 0), 'cache_read' => $cached, 'cache_write' => 0, 'cost' => null]);
            if (empty($m['tool_calls'])) return (string)($m['content'] ?? '');
            $msgs[] = $m;
            $images = [];
            foreach ($m['tool_calls'] as $c) {
                $args = json_decode($c['function']['arguments'] ?: '{}', true) ?: [];
                if ($onEvent) $onEvent(['type' => 'tool', 'name' => $c['function']['name'], 'input' => $args]);
                [$out, $imgs] = Content::toolImages($tools->run($c['function']['name'], $args));
                array_push($images, ...$imgs);
                $msgs[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => $out];
            }
            // tool messages are text-only: images (screenshots) follow as a user message
            if ($images) $msgs[] = ['role' => 'user', 'content' => Content::openai(array_merge([['type' => 'text', 'text' => 'Screenshot(s) from the tool call above:']], $images))];
        }
        // out of steps: one last answer without tools says where things stand (instead of a bare "stopped")
        $msgs[] = ['role' => 'user', 'content' => 'Tool step limit reached: no more tools. Answer the user now, in their language: what is done, what is not, and what you need from them to finish.'];
        $last = $payload + ['messages' => $msgs, 'tool_choice' => 'none'];
        $r = $onEvent ? $this->stream($last, $onEvent) : Http::json("{$this->baseUrl}/chat/completions", $last, ['Authorization: Bearer ' . ($this->key ?: 'none')], 300);
        $u = $r['usage'] ?? ['prompt_tokens' => (int)(strlen(json_encode($msgs)) / 4), 'completion_tokens' => 0];
        $onUsage(['model' => $this->tier->model, 'in' => (int)($u['prompt_tokens'] ?? 0), 'out' => (int)($u['completion_tokens'] ?? 0), 'cache_read' => 0, 'cache_write' => 0, 'cost' => null]);
        return (string)($r['choices'][0]['message']['content'] ?? '') ?: '(stopped after too many tool steps)';
    }

    /** Streamed chat completion reassembled into a normal response (tool call fragments merged, extra fields kept). */
    private function stream(array $payload, callable $onEvent): array
    {
        $msg = ['role' => 'assistant', 'content' => '']; $calls = []; $usage = null;
        Http::sse("{$this->baseUrl}/chat/completions", $payload + ['stream' => true] + ($this->plainStream ? [] : ['stream_options' => ['include_usage' => true]]),
            ['Authorization: Bearer ' . ($this->key ?: 'none')], function (string $ev, array $d) use (&$msg, &$calls, &$usage, $onEvent) {
                if (isset($d['usage'])) $usage = $d['usage'];
                if (isset($d['error'])) throw new \RuntimeException('API error: ' . ($d['error']['message'] ?? json_encode($d['error'])));
                $delta = $d['choices'][0]['delta'] ?? [];
                if (($delta['content'] ?? '') !== '') { $msg['content'] .= $delta['content']; $onEvent(['type' => 'text', 'text' => $delta['content']]); }
                foreach ($delta['tool_calls'] ?? [] as $k => $tc) {
                    $idx = $tc['index'] ?? $k;
                    $cur = $calls[$idx] ?? ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    if (isset($tc['id'])) $cur['id'] = $tc['id'];
                    if (isset($tc['function']['name'])) $cur['function']['name'] .= $tc['function']['name'];
                    if (isset($tc['function']['arguments'])) $cur['function']['arguments'] .= $tc['function']['arguments'];
                    foreach ($tc as $key => $v) if (!in_array($key, ['index', 'id', 'function', 'type'], true)) $cur[$key] = $v; // e.g. Gemini thought signatures
                    $calls[$idx] = $cur;
                }
            });
        if ($calls) { ksort($calls); $msg['tool_calls'] = array_values($calls); if ($msg['content'] === '') $msg['content'] = null; }
        return ['choices' => [['message' => $msg]], 'usage' => $usage];
    }
}
