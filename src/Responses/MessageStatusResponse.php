<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class MessageStatusResponse
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $status,
        public readonly ?string $smsType,
        public readonly string $price,
        public readonly string $currency,
        public readonly ?string $errorCode,
        public readonly ?string $createdAt,
        public readonly ?string $sentAt,
        public readonly ?string $deliveredAt,
        /** "production" or "sandbox". */
        public readonly string $environment = 'production',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (string) $data['message_id'],
            channel: (string) $data['channel'],
            recipient: (string) $data['recipient'],
            status: (string) $data['status'],
            smsType: $data['sms_type'] ?? null,
            price: (string) $data['price'],
            currency: (string) $data['currency'],
            errorCode: $data['error_code'] ?? null,
            createdAt: $data['created_at'] ?? null,
            sentAt: $data['sent_at'] ?? null,
            deliveredAt: $data['delivered_at'] ?? null,
            environment: (string) ($data['environment'] ?? 'production'),
        );
    }
}
