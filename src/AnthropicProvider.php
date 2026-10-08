<?php
declare(strict_types=1);
namespace Talixio;

final class AnthropicProvider implements Provider
{
    public function __construct(private string $key, private string $model, private bool $webSearch, private string $baseUrl = 'https://api.anthropic.com') {}

    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string
    {
        $specs = array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools->schemas());
        if ($this->webSearch) $specs[] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];
        for ($i = 0; $i < 12; $i++) {
            $r = Http::json("{$this->baseUrl}/v1/messages", [
                'model' => $this->model, 'max_tokens' => 4096, 'system' => $system,
                'messages' => $messages, 'tools' => $specs,
            ], ['x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01']);
            $onUsage($this->model, (int)($r['usage']['input_tokens'] ?? 0), (int)($r['usage']['output_tokens'] ?? 0), null);
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
            $messages[] = ['role' => 'assistant', 'content' => $blocks];
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
