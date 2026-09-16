<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

use Throwable;

class ValidationException extends NotifyException
{
    /**
     * @param  array<string, array<int, string>>  $errors  field => messages, when the server sent field-level detail
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        ?string $errorCode = null,
        ?int $statusCode = null,
        ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody, $previous);
    }
}
