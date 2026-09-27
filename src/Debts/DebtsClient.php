<?php

declare(strict_types=1);

namespace CloudMe\Notify\Debts;

use CloudMe\Notify\Exceptions\ForbiddenException;
use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\NotifyClient;
use CloudMe\Notify\Responses\DebtResponse;
use CloudMe\Notify\Responses\RecordPaymentResponse;
use DateTimeInterface;

/**
 * `$notify->debts()` - hand a debt over for collection and report payments.
 * Notify then reminds the debtor channel by channel (per the collection
 * strategy) until the debt is fully paid. Requires the `debts:manage` scope.
 *
 * ```php
 * $debt = $notify->debts()->create(
 *     name: 'Aziz Karimov',
 *     phone: '998901234567',
 *     amount: 1250000,
 *     dueDate: '2026-10-15',
 *     externalId: 'INV-1001',
 * );
 *
 * // later, from your billing system:
 * $result = $notify->debts()->recordPayment($debt->id, 1250000, externalId: 'PAY-5001');
 * $result->debt->isPaid();                  // true
 * $result->payment->attributedChannel;      // e.g. "sms" - the reminder that got them to pay
 * ```
 */
final class DebtsClient
{
    public function __construct(private readonly NotifyClient $client) {}

    /**
     * With $externalId the call is idempotent - resending it returns the
     * existing debt instead of creating a second one - so only then is it
     * retried automatically on a network failure.
     *
     * @param  string|null  $strategyId  collection strategy id (dashboard -> Collection -> Strategies -> API ID); null = your default strategy
     *
     * @throws ValidationException `STRATEGY_NOT_FOUND`, or invalid fields
     * @throws ForbiddenException when the API client lacks the `debts:manage` scope
     */
    public function create(
        string $name,
        string $phone,
        int|float|string $amount,
        DateTimeInterface|string $dueDate,
        ?string $externalId = null,
        ?string $email = null,
        ?string $currency = null,
        ?string $description = null,
        ?string $strategyId = null,
    ): DebtResponse {
        $response = $this->client->debtRequest('POST', 'debts', array_filter([
            'name' => $name,
            'phone' => $phone,
            'amount' => $amount,
            'due_date' => $dueDate instanceof DateTimeInterface ? $dueDate->format('Y-m-d') : $dueDate,
            'external_id' => $externalId,
            'email' => $email,
            'currency' => $currency,
            'description' => $description,
            'strategy_id' => $strategyId,
        ], static fn ($value) => $value !== null), retryable: $externalId !== null);

        return DebtResponse::fromArray($response['debt']);
    }

    /**
     * The debt with all its payments.
     *
     * @throws NotFoundException
     */
    public function find(string $debtId): DebtResponse
    {
        $response = $this->client->debtRequest('GET', "debts/{$debtId}", retryable: true);

        return DebtResponse::fromArray([...$response['debt'], 'payments' => $response['payments'] ?? []]);
    }

    /**
     * Reports a (partial) payment. Paying off the remainder closes the debt
     * and stops reminders. With $externalId a repeat returns the original
     * payment, so only then is it retried automatically.
     *
     * @param  DateTimeInterface|string|null  $paidAt  when the debtor paid; null = now
     *
     * @throws ValidationException `EXCEEDS_REMAINING`, `DEBT_CLOSED`, `INVALID_AMOUNT`
     * @throws NotFoundException
     */
    public function recordPayment(
        string $debtId,
        int|float|string $amount,
        DateTimeInterface|string|null $paidAt = null,
        ?string $externalId = null,
    ): RecordPaymentResponse {
        $response = $this->client->debtRequest('POST', "debts/{$debtId}/payments", array_filter([
            'amount' => $amount,
            'paid_at' => $paidAt instanceof DateTimeInterface ? $paidAt->format(DateTimeInterface::ATOM) : $paidAt,
            'external_id' => $externalId,
        ], static fn ($value) => $value !== null), retryable: $externalId !== null);

        return RecordPaymentResponse::fromArray($response);
    }

    /**
     * Stops collecting: no further reminders, no further payments.
     *
     * @throws ValidationException `DEBT_CLOSED` when it is already paid or cancelled
     * @throws NotFoundException
     */
    public function cancel(string $debtId): DebtResponse
    {
        $response = $this->client->debtRequest('POST', "debts/{$debtId}/cancel");

        return DebtResponse::fromArray($response['debt']);
    }
}
