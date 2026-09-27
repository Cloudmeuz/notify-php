<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class DebtPaymentResponse
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $externalId,
        public readonly string $amount,
        public readonly string $paidAt,
        /** Channel of the last delivered reminder before this payment (null if none) - the one credited with it. */
        public readonly ?string $attributedChannel,
        /** Days after the due date (negative = early, 0 = on the due date). */
        public readonly int $daysLate,
    ) {}

    public function isLate(): bool
    {
        return $this->daysLate > 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            externalId: $data['external_id'] ?? null,
            amount: (string) $data['amount'],
            paidAt: (string) $data['paid_at'],
            attributedChannel: $data['attributed_channel'] ?? null,
            daysLate: (int) $data['days_late'],
        );
    }
}
