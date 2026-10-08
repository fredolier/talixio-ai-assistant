<?php
declare(strict_types=1);
namespace Talixio;

/** Minimal MCP server over stdio (newline-delimited JSON-RPC) exposing Talixio's tools to Claude Code. */
final class McpServer
{
    public function __construct(private Tools $tools) {}

    public function serve($in = STDIN, $out = STDOUT): void
    {
        while (($line = fgets($in)) !== false) {
            $req = json_decode(trim($line), true);
            if (!is_array($req) || !isset($req['method'])) continue;
            $resp = $this->handle($req);
            if ($resp !== null) fwrite($out, json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    public function handle(array $req): ?array
    {
        if (!array_key_exists('id', $req)) return null; // notification
        $id = $req['id'];
        $p = $req['params'] ?? [];
        $result = match ($req['method']) {
            'initialize' => [
                'protocolVersion' => $p['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'talixio', 'version' => '0.2.0'],
            ],
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => array_map(fn($t) => [
                'name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['parameters'],
            ], $this->tools->schemas())],
            'tools/call' => (function () use ($p) {
                $text = $this->tools->run((string)($p['name'] ?? ''), (array)($p['arguments'] ?? []));
                return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => str_starts_with($text, 'error:')];
            })(),
            default => null,
        };
        return $result === null
            ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'method not found']]
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
