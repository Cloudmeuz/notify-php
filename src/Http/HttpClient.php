<?php

declare(strict_types=1);

namespace CloudMe\Notify\Http;

use CloudMe\Notify\Exceptions\ApiException;
use CloudMe\Notify\Exceptions\AuthenticationException;
use CloudMe\Notify\Exceptions\ForbiddenException;
use CloudMe\Notify\Exceptions\InsufficientBalanceException;
use CloudMe\Notify\Exceptions\NetworkException;
use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\NotifyException;
use CloudMe\Notify\Exceptions\RateLimitException;
use CloudMe\Notify\Exceptions\ServerException;
use CloudMe\Notify\Exceptions\ValidationException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thin wrapper around Guzzle that turns every non-2xx response (and every
 * transport-level failure) into a typed {@see NotifyException}, and retries
 * a request a bounded number of times when it is safe to do so.
 */
final class HttpClient
{
    private const MAX_RETRYABLE_ATTEMPTS = 3;

    private const RETRY_BASE_DELAY_MS = 400;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $maxRetries = self::MAX_RETRYABLE_ATTEMPTS,
    ) {}

    /**
     * @param  array<string, mixed>  $json  request body, omitted entirely for methods like GET
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> the decoded JSON response body
     *
     * @throws NotifyException
     */
    public function request(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        ?string $bearerToken = null,
        ?string $idempotencyKey = null,
        bool $retryable = false,
    ): array {
        $headers = ['Accept' => 'application/json'];

        if ($bearerToken !== null) {
            $headers['Authorization'] = "Bearer {$bearerToken}";
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = ['headers' => $headers];

        if ($json !== []) {
            $options['json'] = $json;
        }

        if ($query !== []) {
            $options['query'] = $query;
        }

        $maxAttempts = $retryable ? $this->maxRetries : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->client->request($method, ltrim($path, '/'), $options);

                $body = (string) $response->getBody();
                $decoded = $body === '' ? [] : json_decode($body, true);

                return is_array($decoded) ? $decoded : [];
            } catch (ConnectException $e) {
                if ($attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($attempt);

                    continue;
                }

                throw new NetworkException("Could not reach the Notify API: {$e->getMessage()}", previous: $e);
            } catch (RequestException $e) {
                $response = $e->getResponse();

                if ($response === null) {
                    if ($attempt < $maxAttempts) {
                        $this->sleepBeforeRetry($attempt);

                        continue;
                    }

                    throw new NetworkException("Could not reach the Notify API: {$e->getMessage()}", previous: $e);
                }

                $status = $response->getStatusCode();
                $decoded = json_decode((string) $response->getBody(), true);
                $decoded = is_array($decoded) ? $decoded : null;

                if ($retryable && $attempt < $maxAttempts && ($status === 429 || $status >= 500)) {
                    $this->sleepBeforeRetry($attempt, $this->retryAfterSeconds($response));

                    continue;
                }

                throw $this->mapErrorResponse($status, $decoded, $response, $e);
            } catch (GuzzleException $e) {
                throw new NetworkException("Notify API request failed: {$e->getMessage()}", previous: $e);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $decoded
     */
    private function mapErrorResponse(int $status, ?array $decoded, ResponseInterface $response, Throwable $previous): NotifyException
    {
        $errorCode = $decoded['error']['code'] ?? null;
        $message = $decoded['error']['message'] ?? $decoded['message'] ?? "Notify API returned HTTP {$status}.";

        return match (true) {
            $status === 401 => new AuthenticationException($message, $errorCode, $status, $decoded, $previous),
            $status === 402 => new InsufficientBalanceException($message, $errorCode, $status, $decoded, $previous),
            $status === 403 => new ForbiddenException($message, $errorCode, $status, $decoded, $previous),
            $status === 404 => new NotFoundException($message, $errorCode, $status, $decoded, $previous),
            $status === 422 => new ValidationException(
                $message,
                errors: is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [],
                errorCode: $errorCode,
                statusCode: $status,
                responseBody: $decoded,
                previous: $previous,
            ),
            $status === 429 => new RateLimitException(
                $message,
                retryAfterSeconds: $this->retryAfterSeconds($response),
                errorCode: $errorCode,
                statusCode: $status,
                responseBody: $decoded,
                previous: $previous,
            ),
            $status >= 500 => new ServerException($message, $errorCode, $status, $decoded, $previous),
            default => new ApiException($message, $errorCode, $status, $decoded, $previous),
        };
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return $header === '' ? null : (int) $header;
    }

    private function sleepBeforeRetry(int $attempt, ?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null) {
            usleep($retryAfterSeconds * 1_000_000);

            return;
        }

        // Exponential backoff with jitter: ~400ms, ~800ms, ~1600ms...
        $delayMs = self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
        usleep((int) (($delayMs + random_int(0, 100)) * 1000));
    }
}
