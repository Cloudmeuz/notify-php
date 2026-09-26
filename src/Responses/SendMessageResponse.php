<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class SendMessageResponse
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly string $price,
        public readonly string $currency,
        public readonly ?string $balance,
        /** "production" or "sandbox" - confirms which one actually handled this send. */
        public readonly string $environment = 'production',
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
            status: (string) $data['status'],
            price: (string) $data['price'],
            currency: (string) $data['currency'],
            balance: isset($data['balance']) ? (string) $data['balance'] : null,
            environment: (string) ($data['environment'] ?? 'production'),
        );
    }
}
