<?php
declare(strict_types=1);
namespace Talixio;

final class AnthropicProvider implements Provider
{
    public function __construct(private string $key, private Tier $tier, private bool $webSearch, private string $baseUrl = 'https://api.anthropic.com') {}

    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage, ?callable $onEvent = null): string
    {
        $messages = Content::messages($messages, [Content::class, 'anthropic']);
        $specs = array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools->schemas());
        if ($this->webSearch && $tools->allowsWeb()) $specs[] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];
        $legacy = self::legacyThinking($this->tier->model);
        $think = $this->tier->thinkingTokens >= 1024 || (!$legacy && $this->tier->reasoningEffort !== '');
        $payload = [
            'model' => $this->tier->model,
            'max_tokens' => $legacy ? 8192 + $this->tier->thinkingTokens : ($think ? 16000 : 8192),
            // the stable part is cached (10% price on re-reads); the per-turn part follows it
            'system' => array_values(array_filter([
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
                $context !== '' ? ['type' => 'text', 'text' => $context] : null,
            ])),
        ];
        if ($specs) $payload['tools'] = $specs;
        if ($think) $payload['thinking'] = $legacy ? ['type' => 'enabled', 'budget_tokens' => $this->tier->thinkingTokens] : ['type' => 'adaptive'];
        if (!$legacy && $this->tier->reasoningEffort !== '') $payload['output_config'] = ['effort' => $this->tier->reasoningEffort];
        for ($i = 0; $i < $this->tier->maxSteps; $i++) {
            $r = $onEvent ? $this->stream($payload + ['messages' => $messages], $onEvent)
                : Http::json("{$this->baseUrl}/v1/messages", $payload + ['messages' => $messages], $this->headers(), 600);
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
                    if ($onEvent) $onEvent(['type' => 'tool', 'name' => $b['name'], 'input' => (array)$b['input']]);
                    [$out, $imgs] = Content::toolImages($tools->run($b['name'], (array)$b['input']));
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $imgs ? Content::anthropic(array_merge([['type' => 'text', 'text' => $out]], $imgs)) : $out];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        return '(stopped after too many tool steps)';
    }

    /** Claude 3.x and 4.0-4.5 take a thinking budget in tokens; later models think adaptively, steered by the effort level. */
    public static function legacyThinking(string $model): bool
    {
        return (bool)preg_match('/^claude-(?:3|(?:opus|sonnet|haiku)-4(?:-[0-5])?(?:-\d{8})?$)/', $model);
    }

    private function headers(): array { return ['x-api-key: ' . $this->key, 'anthropic-version: 2023-06-01']; }

    /** Streamed request, reassembled into the same shape as a non-streamed response. */
    private function stream(array $payload, callable $onEvent): array
    {
        $msg = ['content' => [], 'stop_reason' => null, 'usage' => []];
        $json = [];
        Http::sse("{$this->baseUrl}/v1/messages", $payload + ['stream' => true], $this->headers(), function (string $ev, array $d) use (&$msg, &$json, $onEvent) {
            switch ($d['type'] ?? $ev) {
                case 'message_start': $msg['usage'] = $d['message']['usage'] ?? []; break;
                case 'content_block_start':
                    $msg['content'][$d['index']] = $d['content_block'];
                    if (($d['content_block']['type'] ?? '') === 'server_tool_use') $onEvent(['type' => 'tool', 'name' => $d['content_block']['name'] ?? 'web_search', 'input' => []]);
                    break;
                case 'content_block_delta':
                    $i = $d['index']; $delta = $d['delta'];
                    match ($delta['type']) {
                        'text_delta' => (function () use (&$msg, $i, $delta, $onEvent) { $msg['content'][$i]['text'] = ($msg['content'][$i]['text'] ?? '') . $delta['text']; $onEvent(['type' => 'text', 'text' => $delta['text']]); })(),
                        'input_json_delta' => $json[$i] = ($json[$i] ?? '') . $delta['partial_json'],
                        'thinking_delta' => $msg['content'][$i]['thinking'] = ($msg['content'][$i]['thinking'] ?? '') . $delta['thinking'],
                        'signature_delta' => $msg['content'][$i]['signature'] = $delta['signature'],
                        'citations_delta' => $msg['content'][$i]['citations'][] = $delta['citation'],
                        default => null,
                    };
                    break;
                case 'content_block_stop':
                    if (isset($json[$d['index']])) { $msg['content'][$d['index']]['input'] = json_decode($json[$d['index']], true) ?: new \stdClass(); unset($json[$d['index']]); }
                    break;
                case 'message_delta':
                    $msg['stop_reason'] = $d['delta']['stop_reason'] ?? $msg['stop_reason'];
                    $msg['usage'] = array_merge($msg['usage'], $d['usage'] ?? []);
                    break;
                case 'error': throw new \RuntimeException('API error: ' . ($d['error']['message'] ?? 'stream error'));
            }
        });
        ksort($msg['content']);
        $msg['content'] = array_values($msg['content']);
        return $msg;
    }
}
