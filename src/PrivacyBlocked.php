<?php
declare(strict_types=1);
namespace Talixio;

/** Something a space's confidentiality level does not allow (an outside service, a tool). */
final class PrivacyBlocked extends \RuntimeException {}
