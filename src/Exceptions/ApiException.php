<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * Fallback for any non-2xx response that doesn't match one of the specific
 * exception types above.
 */
class ApiException extends NotifyException {}
