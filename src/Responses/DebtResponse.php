<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class DebtResponse
{
    /**
     * @param  list<DebtPaymentResponse>  $payments  only filled by DebtsClient::find()
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $externalId,
        public readonly string $name,
        public readonly string $phone,
        public readonly string $amount,
        public readonly string $paidAmount,
        public readonly string $remainingAmount,
        public readonly string $currency,
        public readonly string $dueDate,
        /** active, paused, paid, exhausted (every step sent, still unpaid) or cancelled. */
        public readonly string $status,
        public readonly string $environment,
        public readonly ?string $nextReminderAt,
        public readonly ?string $paidAt,
        /** Channel of the last delivered reminder before the closing payment - null if paid before any reminder. */
        public readonly ?string $paidChannel,
        /** Days between the due date and the closing payment (negative = early). */
        public readonly ?int $paidDaysLate,
        public readonly array $payments = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            externalId: $data['external_id'] ?? null,
            name: (string) $data['name'],
            phone: (string) $data['phone'],
            amount: (string) $data['amount'],
            paidAmount: (string) $data['paid_amount'],
            remainingAmount: (string) $data['remaining_amount'],
            currency: (string) $data['currency'],
            dueDate: (string) $data['due_date'],
            status: (string) $data['status'],
            environment: (string) ($data['environment'] ?? 'production'),
            nextReminderAt: $data['next_reminder_at'] ?? null,
            paidAt: $data['paid_at'] ?? null,
            paidChannel: $data['paid_channel'] ?? null,
            paidDaysLate: isset($data['paid_days_late']) ? (int) $data['paid_days_late'] : null,
            payments: array_map(static fn (array $payment) => DebtPaymentResponse::fromArray($payment), $data['payments'] ?? []),
        );
    }
}
