<?php
declare(strict_types=1);
namespace Talixio;

interface Provider
{
    /**
     * Run the full tool-use loop and return the final text.
     * @param array $messages [{role, content}]
     * @param callable $runTool fn(string $name, array $args): string
     */
    public function complete(string $system, array $messages, array $tools, callable $runTool): string;
}
