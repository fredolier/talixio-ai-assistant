<?php
declare(strict_types=1);
namespace Talixio;

/** A remote connector needs the user to sign in (Settings → Connectors). */
final class McpAuthRequired extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $metadata = null) { parent::__construct($message); }
}
