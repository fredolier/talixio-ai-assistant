<?php
declare(strict_types=1);
namespace Talixio;

/** TALIXIO_PROVIDER=echo: no model, repeats your message. For checking an install before adding an API key. */
final class EchoProvider implements Provider
{
    public function complete(string $system, array $messages, Tools $tools, callable $onUsage): string
    {
        $last = (string)(end($messages)['content'] ?? '');
        if (str_contains($last, 'Reply with exactly: pong') || $last === 'ping') return 'pong';
        return "Echo mode (no AI model configured yet): \"$last\"\n\nSet TALIXIO_PROVIDER and an API key in /etc/talixio/talixio.env, then restart.";
    }
}
