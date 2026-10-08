<?php
declare(strict_types=1);
namespace Talixio;

interface Provider
{
    /**
     * Run the whole tool-use loop and return the final text.
     * @param array $messages [{role: user|assistant, content: string}]
     * @param callable $runTool fn(string $name, array $args): string
     * @param callable $onUsage fn(string $model, int $in, int $out, ?float $costUsd): void
     */
    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string;
}
