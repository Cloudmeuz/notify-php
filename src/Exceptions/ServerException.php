<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * A 5xx from the API - the SDK already retries these with backoff before
 * giving up and surfacing this, see HttpClient::MAX_RETRYABLE_ATTEMPTS.
 */
class ServerException extends NotifyException {}
