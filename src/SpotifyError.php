<?php
declare(strict_types=1);
namespace Talixio;

/** A Spotify failure with its kind (no device, not premium...), so the app can say what to do. */
final class SpotifyError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind, int $code = 0) { parent::__construct($message, $code); }
}
