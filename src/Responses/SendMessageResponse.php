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
