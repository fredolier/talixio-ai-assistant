<?php
declare(strict_types=1);
namespace Talixio;

final class Agent
{
    public Tools $tools;

    public function __construct(public Config $cfg, public Provider $provider, public Memory $memory)
    {
        $this->tools = new Tools($memory);
    }

    private function system(string $chat): string
    {
        $facts = $this->memory->facts($chat);
        $known = $facts ? implode("\n", array_map(fn($f) => "- $f", $facts)) : '(nothing yet)';
        return "You are {$this->cfg->name}. {$this->cfg->persona}\n"
            . 'Current UTC time: ' . gmdate('Y-m-d H:i') . "\n"
            . "Use the remember tool for durable user facts.\nKnown facts:\n$known";
    }

    public function reply(string $chat, string $text): string
    {
        $this->memory->addMessage($chat, 'user', $text);
        $out = trim($this->provider->complete(
            $this->system($chat),
            $this->memory->history($chat, $this->cfg->historyTurns),
            $this->tools->schemas(),
            fn(string $n, array $a) => $this->tools->run($chat, $n, $a),
        )) ?: '(no response)';
        $this->memory->addMessage($chat, 'assistant', $out);
        return $out;
    }
}
