<?php
declare(strict_types=1);
namespace Talixio;

/** Someone else changed the record since it was loaded. */
final class Conflict extends \RuntimeException {}
