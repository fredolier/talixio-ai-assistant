<?php
declare(strict_types=1);
namespace Talixio;

/** Tools the model may call. Add yours with register(). */
final class Tools
{
    /** @var array<string,array{0:array,1:callable}> */
    private array $tools = [];

    public function __construct(private Memory $memory)
    {
        $this->register('remember', 'Save a durable fact about the user or their preferences.',
            ['text' => ['type' => 'string']], ['text'],
            function (string $chat, array $a) { $this->memory->remember($chat, $a['text']); return 'saved'; });
        $this->register('forget', 'Delete saved facts containing the given text.',
            ['text' => ['type' => 'string']], ['text'],
            fn(string $chat, array $a) => 'deleted ' . $this->memory->forget($chat, $a['text']));
        $this->register('now', 'Current UTC date and time.', [], [],
            fn() => gmdate('c'));
        $this->register('fetch_url', 'Fetch a web page (first 8000 chars).',
            ['url' => ['type' => 'string']], ['url'],
            function (string $chat, array $a) {
                if (!preg_match('#^https?://#i', $a['url'])) return 'error: only http(s) urls';
                return substr(Http::request('GET', $a['url'], [], null, 15)[1], 0, 8000);
            });
    }

    public function register(string $name, string $desc, array $props, array $required, callable $fn): void
    {
        $this->tools[$name] = [[
            'name' => $name, 'description' => $desc,
            // empty properties must encode as {} not []
            'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $required],
        ], $fn];
    }

    public function schemas(): array { return array_map(fn($t) => $t[0], array_values($this->tools)); }

    public function run(string $chat, string $name, array $args): string
    {
        if (!isset($this->tools[$name])) return "error: unknown tool $name";
        try { return (string)($this->tools[$name][1])($chat, $args); }
        catch (\Throwable $e) { return 'error: ' . $e->getMessage(); } // errors go back to the model
    }
}
