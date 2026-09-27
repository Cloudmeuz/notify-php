<?php

declare(strict_types=1);

namespace CloudMe\Notify\Webhooks;

use CloudMe\Notify\Exceptions\ConfigurationException;
use CloudMe\Notify\Exceptions\InvalidWebhookSignatureException;
use JsonException;

/**
 * Verifies and parses a webhook Notify POSTs to your API client's webhook
 * URL. X-Signature is the hex HMAC-SHA256 of "{X-Webhook-Timestamp}.{raw
 * body}" with the webhook's signing secret (dashboard -> API clients ->
 * Webhook); a timestamp outside the tolerance window is rejected so a
 * captured request cannot be replayed later.
 *
 * ```php
 * $verifier = new WebhookVerifier(getenv('NOTIFY_WEBHOOK_SECRET'));
 *
 * $event = $verifier->parse(file_get_contents('php://input'), getallheaders());
 *
 * if ($event->event === 'message.failed') { ... }
 * // Dedupe on $event->id - a retried delivery carries the same id.
 * ```
 *
 * Always pass the RAW request body - re-encoding a decoded JSON array
 * changes the bytes and breaks the signature.
 */
final class WebhookVerifier
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ) {
        if (trim($secret) === '') {
            throw new ConfigurationException('The webhook signing secret is required.');
        }
    }

    /**
     * @param  int|null  $now  current Unix time - only for tests
     */
    public function isValid(string $rawBody, string $signature, string|int $timestamp, ?int $now = null): bool
    {
        if (! ctype_digit((string) $timestamp) || abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * @param  array<string, string|list<string>>  $headers  request headers in any letter case, e.g. getallheaders() or $request->headers->all()
     * @param  int|null  $now  current Unix time - only for tests
     *
     * @throws InvalidWebhookSignatureException
     */
    public function parse(string $rawBody, array $headers, ?int $now = null): WebhookEvent
    {
        $header = static function (string $name) use ($headers): string {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $name) === 0) {
                    return (string) (is_array($value) ? ($value[0] ?? '') : $value);
                }
            }

            return '';
        };

        $timestamp = $header('X-Webhook-Timestamp');

        if (! $this->isValid($rawBody, $header('X-Signature'), $timestamp, $now)) {
            throw new InvalidWebhookSignatureException('Notify webhook signature or timestamp is invalid.');
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidWebhookSignatureException('Notify webhook body is not valid JSON.', previous: $e);
        }

        return WebhookEvent::fromPayload($header('X-Webhook-Id'), (int) $timestamp, is_array($payload) ? $payload : []);
    }
}
