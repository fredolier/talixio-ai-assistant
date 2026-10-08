<?php
declare(strict_types=1);
namespace Talixio;

/** Thrown from a usage callback to stop a run: budget exhausted, job cost cap reached, or job cancelled. */
final class Abort extends \RuntimeException {}
