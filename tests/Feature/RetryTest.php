<?php

use CloudMe\Notify\Exceptions\NetworkException;
use CloudMe\Notify\Exceptions\RateLimitException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

test('a transient 500 on a retryable call is retried and eventually succeeds', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(500, ['error' => ['message' => 'boom']], ['Retry-After' => '0']),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS']),
    ], $history);

    $response = $client->sms()->send(to: '998901234567', message: 'hi');

    expect($response->messageId)->toBe('msg-1');
    expect($history)->toHaveCount(3);
});

test('a persistently rate-limited call gives up after the retry budget and surfaces RateLimitException', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(429, ['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.']], ['Retry-After' => '0']),
        jsonResponse(429, ['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.']], ['Retry-After' => '0']),
    ], options: ['max_retries' => 2]);

    try {
        $client->sms()->send(to: '998901234567', message: 'hi');
        test()->fail('Expected RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->statusCode)->toBe(429);
        expect($e->retryAfterSeconds)->toBe(0);
    }
});

test('a connection-level failure is retried and then surfaces as NetworkException once exhausted', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        new ConnectException('Connection refused', new Request('POST', 'messages/sms')),
        new ConnectException('Connection refused', new Request('POST', 'messages/sms')),
    ], options: ['max_retries' => 2]);

    expect(fn () => $client->sms()->send(to: '998901234567', message: 'hi'))->toThrow(NetworkException::class);
});

test('a non-retryable call (no idempotency key context, e.g. balance()) still retries on 5xx since reads are always safe', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(500, ['error' => ['message' => 'boom']], ['Retry-After' => '0']),
        jsonResponse(200, ['success' => true, 'balance' => '1000.00', 'reserved_balance' => '0.00', 'currency' => 'UZS']),
    ], $history);

    $balance = $client->balance();

    expect($balance->balance)->toBe('1000.00');
    expect($history)->toHaveCount(3);
});
