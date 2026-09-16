<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception the SDK throws for an API-level failure
 * (as opposed to a local misconfiguration, see ConfigurationException).
 */
class NotifyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
