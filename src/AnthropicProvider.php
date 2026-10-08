<?php
declare(strict_types=1);
namespace Talixio;

final class AnthropicProvider implements Provider
{
    public function __construct(private string $key, private string $model) {}

    public function complete(string $system, array $messages, array $tools, callable $runTool): string
    {
        $specs = array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools);
        for ($i = 0; $i < 8; $i++) {
            $r = Http::json('https://api.anthropic.com/v1/messages', [
                'model' => $this->model, 'max_tokens' => 2048, 'system' => $system,
                'messages' => $messages, 'tools' => $specs,
            ], ['x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01']);
            $blocks = $r['content'] ?? [];
            if (($r['stop_reason'] ?? '') !== 'tool_use') {
                return implode('', array_map(fn($b) => $b['type'] === 'text' ? $b['text'] : '', $blocks));
            }
            $messages[] = ['role' => 'assistant', 'content' => $blocks];
            $results = [];
            foreach ($blocks as $b) {
                if ($b['type'] === 'tool_use') {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $runTool($b['name'], (array)$b['input'])];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        return '(stopped: too many tool steps)';
    }
}
