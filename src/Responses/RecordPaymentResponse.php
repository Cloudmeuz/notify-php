<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class RecordPaymentResponse
{
    public function __construct(
        public readonly DebtPaymentResponse $payment,
        /** The debt after this payment - check `isPaid()` / `remainingAmount`. */
        public readonly DebtResponse $debt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            payment: DebtPaymentResponse::fromArray($data['payment']),
            debt: DebtResponse::fromArray($data['debt']),
        );
    }
}
