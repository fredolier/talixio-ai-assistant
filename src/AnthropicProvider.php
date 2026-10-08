<?php
declare(strict_types=1);
namespace Talixio;

final class AnthropicProvider implements Provider
{
    public function __construct(private string $key, private Tier $tier, private bool $webSearch, private string $baseUrl = 'https://api.anthropic.com') {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage): string
    {
        $specs = array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools->schemas());
        if ($this->webSearch && $tools->allowsWeb()) $specs[] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];
        $payload = [
            'model' => $this->tier->model,
            'max_tokens' => 4096 + $this->tier->thinkingTokens,
            // the stable part is cached (10% price on re-reads); the per-turn part follows it
            'system' => array_values(array_filter([
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
                $context !== '' ? ['type' => 'text', 'text' => $context] : null,
            ])),
        ];
        if ($specs) $payload['tools'] = $specs;
        if ($this->tier->thinkingTokens >= 1024) $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $this->tier->thinkingTokens];
        for ($i = 0; $i < $this->tier->maxSteps; $i++) {
            $r = Http::json("{$this->baseUrl}/v1/messages", $payload + ['messages' => $messages],
                ['x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01'], 300);
            $u = $r['usage'] ?? [];
            $onUsage(['model' => $this->tier->model, 'in' => (int)($u['input_tokens'] ?? 0), 'out' => (int)($u['output_tokens'] ?? 0),
                'cache_read' => (int)($u['cache_read_input_tokens'] ?? 0), 'cache_write' => (int)($u['cache_creation_input_tokens'] ?? 0), 'cost' => null]);
            $blocks = array_map(function ($b) { // empty tool input must go back as {} not []
                if (in_array($b['type'] ?? '', ['tool_use', 'server_tool_use'], true) && ($b['input'] ?? []) === []) $b['input'] = new \stdClass();
                return $b;
            }, $r['content'] ?? []);
            $stop = $r['stop_reason'] ?? '';
            if ($stop === 'pause_turn') { // long server-side tool run: continue the same turn
                $messages[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }
            if ($stop !== 'tool_use') {
                return implode('', array_map(fn($b) => $b['type'] === 'text' ? $b['text'] : '', $blocks));
            }
            $messages[] = ['role' => 'assistant', 'content' => $blocks]; // includes thinking blocks, required with tools
            $results = [];
            foreach ($blocks as $b) {
                if ($b['type'] === 'tool_use') {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $tools->run($b['name'], (array)$b['input'])];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        return '(stopped after too many tool steps)';
    }
}
