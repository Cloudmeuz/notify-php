<?php

use CloudMe\Notify\Exceptions\ForbiddenException;
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

test('the locale is sent as Accept-Language on every request, and omitted when not set', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'balance' => '1.00', 'reserved_balance' => '0.00', 'currency' => 'UZS']),
    ], $history, locale: 'ru');
    $client->balance();

    $defaultHistory = [];
    $default = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'balance' => '1.00', 'reserved_balance' => '0.00', 'currency' => 'UZS']),
    ], $defaultHistory);
    $default->balance();

    expect($history[0]['request']->getHeaderLine('Accept-Language'))->toBe('ru')
        ->and($history[1]['request']->getHeaderLine('Accept-Language'))->toBe('ru')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toStartWith('Bearer ')
        ->and($defaultHistory[1]['request']->hasHeader('Accept-Language'))->toBeFalse();
});

test('an error exposes the server hint and docs link', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(403, ['success' => false, 'error' => [
            'code' => 'SCOPE_FORBIDDEN',
            'message' => "This action requires the 'balance:read' scope.",
            'hint' => 'Grant balance:read to the API client in the dashboard, then get a new token.',
            'docs_url' => 'https://notify.cloudme.uz/api-docs#error-SCOPE_FORBIDDEN',
            'required_scope' => 'balance:read',
        ]]),
    ]);

    try {
        $client->balance();
        $this->fail('Expected a ForbiddenException.');
    } catch (ForbiddenException $exception) {
        expect($exception->errorCode)->toBe('SCOPE_FORBIDDEN')
            ->and($exception->hint())->toBe('Grant balance:read to the API client in the dashboard, then get a new token.')
            ->and($exception->docsUrl())->toBe('https://notify.cloudme.uz/api-docs#error-SCOPE_FORBIDDEN')
            ->and($exception->requiredScope())->toBe('balance:read')
            ->and($exception->missingVariables())->toBe([]);
    }
});
