<?php

use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\ServerException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\Responses\DebtResponse;

function debtBody(array $overrides = []): array
{
    return [
        'id' => 'debt-uuid-1',
        'external_id' => 'INV-1001',
        'name' => 'Aziz Karimov',
        'phone' => '998901234567',
        'amount' => '1250000.00',
        'paid_amount' => '0.00',
        'remaining_amount' => '1250000.00',
        'currency' => 'UZS',
        'due_date' => '2026-10-15',
        'status' => 'active',
        'environment' => 'production',
        'next_reminder_at' => '2026-10-16T10:00:00+05:00',
        'paid_at' => null,
        'paid_channel' => null,
        'paid_days_late' => null,
        ...$overrides,
    ];
}

test('debts()->create() posts the debt and maps the response', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(201, ['success' => true, 'debt' => debtBody()]),
    ], $history);

    $debt = $client->debts()->create(
        name: 'Aziz Karimov',
        phone: '998901234567',
        amount: 1250000,
        dueDate: new DateTimeImmutable('2026-10-15'),
        externalId: 'INV-1001',
        strategyId: 'strategy-uuid',
    );

    expect($debt)->toBeInstanceOf(DebtResponse::class);
    expect($debt->id)->toBe('debt-uuid-1');
    expect($debt->remainingAmount)->toBe('1250000.00');
    expect($debt->isActive())->toBeTrue();

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toEndWith('/debts');
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'name' => 'Aziz Karimov',
        'phone' => '998901234567',
        'amount' => 1250000,
        'due_date' => '2026-10-15',
        'external_id' => 'INV-1001',
        'strategy_id' => 'strategy-uuid',
    ]);
});

test('create() is retried on a server error only when an external_id makes it idempotent', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(503, ['message' => 'down']),
        jsonResponse(200, ['success' => true, 'debt' => debtBody()]),
    ], $history);

    $client->debts()->create(name: 'Aziz', phone: '998901234567', amount: 1000, dueDate: '2026-10-15', externalId: 'INV-1');
    expect($history)->toHaveCount(3);

    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(503, ['message' => 'down']),
    ], $history);

    expect(fn () => $client->debts()->create(name: 'Aziz', phone: '998901234567', amount: 1000, dueDate: '2026-10-15'))
        ->toThrow(ServerException::class);
    expect($history)->toHaveCount(2);
});

test('find() returns the debt with its payments', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'debt' => debtBody(['status' => 'paid', 'paid_channel' => 'sms', 'paid_days_late' => 2]), 'payments' => [
            ['id' => 'pay-1', 'external_id' => 'PAY-1', 'amount' => '1250000.00', 'paid_at' => '2026-10-17T12:00:00+05:00', 'attributed_channel' => 'sms', 'days_late' => 2],
        ]]),
    ]);

    $debt = $client->debts()->find('debt-uuid-1');

    expect($debt->isPaid())->toBeTrue();
    expect($debt->paidChannel)->toBe('sms');
    expect($debt->payments)->toHaveCount(1);
    expect($debt->payments[0]->attributedChannel)->toBe('sms');
    expect($debt->payments[0]->isLate())->toBeTrue();
});

test('recordPayment() posts the payment and returns it with the updated debt', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(201, [
            'success' => true,
            'payment' => ['id' => 'pay-1', 'external_id' => 'PAY-1', 'amount' => '250000.00', 'paid_at' => '2026-10-16T12:00:00+05:00', 'attributed_channel' => 'telegram', 'days_late' => 1],
            'debt' => debtBody(['paid_amount' => '250000.00', 'remaining_amount' => '1000000.00']),
        ]),
    ], $history);

    $result = $client->debts()->recordPayment('debt-uuid-1', '250000', new DateTimeImmutable('2026-10-16T12:00:00+05:00'), 'PAY-1');

    expect($result->payment->attributedChannel)->toBe('telegram');
    expect($result->debt->remainingAmount)->toBe('1000000.00');

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toEndWith('/debts/debt-uuid-1/payments');
    expect(json_decode((string) $request->getBody(), true))->toBe(['amount' => '250000', 'paid_at' => '2026-10-16T12:00:00+05:00', 'external_id' => 'PAY-1']);
});

test('a payment above the remaining debt surfaces as a ValidationException with its code', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['success' => false, 'error' => ['code' => 'EXCEEDS_REMAINING', 'message' => 'too much']]),
    ]);

    try {
        $client->debts()->recordPayment('debt-uuid-1', 99999999);
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errorCode)->toBe('EXCEEDS_REMAINING');
    }
});

test('cancel() posts to the cancel endpoint and an unknown debt is a NotFoundException', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'debt' => debtBody(['status' => 'cancelled'])]),
        jsonResponse(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Qarz topilmadi.']]),
    ], $history);

    expect($client->debts()->cancel('debt-uuid-1')->status)->toBe('cancelled');
    expect((string) $history[1]['request']->getUri())->toEndWith('/debts/debt-uuid-1/cancel');

    expect(fn () => $client->debts()->find('missing'))->toThrow(NotFoundException::class);
});
