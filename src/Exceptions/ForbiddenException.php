<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * The client is authenticated but not allowed to do this - a revoked client,
 * a blocked company, a disallowed IP, or a missing OAuth scope.
 */
class ForbiddenException extends NotifyException {}
