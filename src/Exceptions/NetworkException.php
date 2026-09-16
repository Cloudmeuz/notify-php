<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * A connection-level failure (timeout, DNS, TLS, refused connection) - no
 * HTTP response was ever received, so there is no statusCode/errorCode.
 */
class NetworkException extends NotifyException {}
