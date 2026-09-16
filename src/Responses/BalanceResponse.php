<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class BalanceResponse
{
    public function __construct(
        public readonly string $balance,
        public readonly string $reservedBalance,
        public readonly string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            balance: (string) $data['balance'],
            reservedBalance: (string) $data['reserved_balance'],
            currency: (string) $data['currency'],
        );
    }
}
