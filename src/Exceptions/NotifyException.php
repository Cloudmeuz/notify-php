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

    /**
     * What to do about this error (`error.hint`), in the language the
     * request asked for via Accept-Language. Null for undocumented codes.
     */
    public function hint(): ?string
    {
        $hint = $this->responseBody['error']['hint'] ?? null;

        return is_string($hint) ? $hint : null;
    }

    /**
     * Link to this error code in the dashboard API docs (`error.docs_url`).
     */
    public function docsUrl(): ?string
    {
        $url = $this->responseBody['error']['docs_url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * SCOPE_FORBIDDEN only: the scope the API client is missing, e.g. "sms:send".
     */
    public function requiredScope(): ?string
    {
        $scope = $this->responseBody['error']['required_scope'] ?? null;

        return is_string($scope) ? $scope : null;
    }

    /**
     * MISSING_TEMPLATE_VARIABLE only: template variables that were not sent.
     *
     * @return list<string>
     */
    public function missingVariables(): array
    {
        $variables = $this->responseBody['error']['missing_variables'] ?? [];

        return is_array($variables) ? array_values(array_filter($variables, 'is_string')) : [];
    }
}
