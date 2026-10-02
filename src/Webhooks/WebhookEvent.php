<?php

declare(strict_types=1);

namespace CloudMe\Notify\Webhooks;

use DateTimeImmutable;
use Exception;

final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  the full decoded body
     */
    public function __construct(
        /** X-Webhook-Id - identical on every retry of the same event; dedupe on it. */
        public readonly string $id,
        /** message.sent, message.delivered, message.failed, message.rejected or webhook.test. */
        public readonly string $event,
        public readonly ?string $messageId,
        public readonly ?string $channel,
        public readonly ?string $recipient,
        public readonly ?string $status,
        public readonly ?string $environment,
        /** Only on message.failed / message.rejected. */
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        /** Unix time the delivery attempt was signed. */
        public readonly int $signedAt,
        public readonly array $payload,
    ) {}

    public function isTest(): bool
    {
        return $this->event === 'webhook.test';
    }

    /**
     * The payload's ISO-8601 `timestamp`: when Notify built this delivery.
     * An automatic retry builds it again; a manual re-send from the
     * dashboard keeps the original. Use the message status, not this, to
     * order events.
     */
    public function occurredAt(): ?DateTimeImmutable
    {
        $timestamp = $this->payload['timestamp'] ?? null;

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $id, int $signedAt, array $payload): self
    {
        return new self(
            id: $id,
            event: (string) ($payload['event'] ?? ''),
            messageId: $payload['message_id'] ?? null,
            channel: $payload['channel'] ?? null,
            recipient: $payload['recipient'] ?? null,
            status: $payload['status'] ?? null,
            environment: $payload['environment'] ?? null,
            errorCode: $payload['error_code'] ?? null,
            errorMessage: $payload['error_message'] ?? null,
            signedAt: $signedAt,
            payload: $payload,
        );
    }
}
