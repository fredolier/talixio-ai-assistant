<?php
declare(strict_types=1);
namespace Talixio;

interface Provider
{
    /**
     * Run the whole tool-use loop and return the final text.
     * @param string $system stable part of the system prompt (cacheable)
     * @param string $context per-turn part (time, budget, recalled memories)
     * @param array $messages [{role: user|assistant, content: string|parts}] (see Content)
     * @param callable $onUsage fn(array{model:string,in:int,out:int,cache_read:int,cache_write:int,cost:?float}): void
     *                 May throw to abort (budget or cancellation).
     * @param callable|null $onEvent fn(array $event): live progress for streaming UIs:
     *                 ['type' => 'text', 'text' => delta] | ['type' => 'tool', 'name' => ..., 'input' => [...]]
     */
    public function complete(string $system, string $context, array $messages, Tools $tools, callable $onUsage, ?callable $onEvent = null): string;
}
