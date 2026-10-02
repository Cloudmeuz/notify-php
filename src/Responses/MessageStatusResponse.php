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
        public readonly ?int $channelAccountId = null,
    ) {}

    /**
     * Free text that matches none of your templates is held until a Notify
     * moderator approves it - send with a template to skip this.
     */
    public function isHeldForModeration(): bool
    {
        return $this->status === 'moderation';
    }

    /**
     * A moderator refused the message; it was not sent and was refunded.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

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
            channelAccountId: isset($data['channel_account_id']) ? (int) $data['channel_account_id'] : null,
        );
    }
}
