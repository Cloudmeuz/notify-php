<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

use InvalidArgumentException;

/**
 * Thrown for a problem entirely on the caller's side - a bad private key, a
 * missing base URL - before any request was even attempted.
 */
class ConfigurationException extends InvalidArgumentException {}
