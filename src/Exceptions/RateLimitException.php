<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

use Throwable;

class RateLimitException extends NotifyException
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?string $errorCode = null,
        ?int $statusCode = null,
        ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody, $previous);
    }
}
