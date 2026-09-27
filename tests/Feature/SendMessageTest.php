<?php

use CloudMe\Notify\Exceptions\InsufficientBalanceException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\Responses\SendMessageResponse;

test('sms()->send() posts to messages/sms with the given fields and maps the response', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-uuid-1', 'status' => 'queued', 'price' => '120.0000', 'currency' => 'UZS', 'balance' => '999880.00']),
    ], $history);

    $response = $client->sms()->send(to: '998901234567', message: 'Tasdiqlash kodi: 481201');

    expect($response)->toBeInstanceOf(SendMessageResponse::class);
    expect($response->messageId)->toBe('msg-uuid-1');
    expect($response->status)->toBe('queued');
    expect($response->price)->toBe('120.0000');
    expect($response->balance)->toBe('999880.00');

    $sendRequest = $history[1]['request'];
    expect((string) $sendRequest->getUri())->toContain('/messages/sms');
    $body = json_decode((string) $sendRequest->getBody(), true);
    expect($body)->toBe(['to' => '998901234567', 'message' => 'Tasdiqlash kodi: 481201']);
});

test('a caller-supplied Idempotency-Key is forwarded as-is', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS']),
    ], $history);

    $client->sms()->send(to: '998901234567', message: 'hi', idempotencyKey: 'order-8912-sms-1');

    expect($history[1]['request']->getHeaderLine('Idempotency-Key'))->toBe('order-8912-sms-1');
});

test('a send with no Idempotency-Key still gets one generated, so a retried call cannot double-send', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS']),
    ], $history);

    $client->sms()->send(to: '998901234567', message: 'hi');

    $key = $history[1]['request']->getHeaderLine('Idempotency-Key');
    expect($key)->not->toBe('');
});

test('sending with a template omits the message field and includes template_id/variables', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS']),
    ], $history);

    $client->sms()->send(to: '998901234567', templateId: 42, variables: ['order_id' => 'A-12891']);

    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body)->toBe(['to' => '998901234567', 'template_id' => 42, 'variables' => ['order_id' => 'A-12891']]);
});

test('the universal channel() escape hatch posts to messages/{channel}', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '10.00', 'currency' => 'UZS']),
    ], $history);

    $client->channel('telegram')->send(to: '82736192', message: 'Buyurtmangiz tayyor');

    expect((string) $history[1]['request']->getUri())->toContain('/messages/telegram');
});

test('voice()->call() is an alias for send() on the voice channel', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '350.00', 'currency' => 'UZS']),
    ], $history);

    $client->voice()->call(to: '998901234567', message: 'Sizning buyurtmangiz yetkazildi');

    expect((string) $history[1]['request']->getUri())->toContain('/messages/voice');
});

test('an insufficient balance error maps to InsufficientBalanceException', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(402, ['success' => false, 'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => "Balansda mablag' yetarli emas."]]),
    ]);

    try {
        $client->sms()->send(to: '998901234567', message: 'hi');
        $this->fail('Expected InsufficientBalanceException.');
    } catch (InsufficientBalanceException $e) {
        expect($e->errorCode)->toBe('INSUFFICIENT_BALANCE');
        expect($e->statusCode)->toBe(402);
    }
});

test('a 422 validation error carries the field-level errors through', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['message' => 'The given data was invalid.', 'errors' => ['to' => ['The to field is required.']]]),
    ]);

    try {
        $client->sms()->send(to: '998901234567', message: 'hi');
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $e) {
        expect($e->statusCode)->toBe(422);
        expect($e->errors)->toBe(['to' => ['The to field is required.']]);
    }
});

test('a photo_url is sent along with a telegram message', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '10.00', 'currency' => 'UZS']),
    ], $history);

    $client->telegram()->send(to: '998901234567', message: '<b>Buyurtma tayyor</b>', photoUrl: 'https://example.com/order.jpg');

    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body)->toBe(['to' => '998901234567', 'message' => '<b>Buyurtma tayyor</b>', 'photo_url' => 'https://example.com/order.jpg']);
});
