<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * The server rejected our credentials, signature, or nonce - never worth
 * retrying automatically (unlike an expired access token, which the SDK
 * already refreshes transparently before this exception would ever surface).
 */
class AuthenticationException extends NotifyException {}
