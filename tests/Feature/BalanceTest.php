<?php

use CloudMe\Notify\Responses\BalanceResponse;

test('balance() fetches and maps the wallet balance', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'balance' => '999880.00', 'reserved_balance' => '0.00', 'currency' => 'UZS']),
    ], $history);

    $balance = $client->balance();

    expect($balance)->toBeInstanceOf(BalanceResponse::class);
    expect($balance->balance)->toBe('999880.00');
    expect($balance->currency)->toBe('UZS');
    expect((string) $history[1]['request']->getUri())->toContain('/balance');
});
