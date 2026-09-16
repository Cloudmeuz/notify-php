<?php

use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Responses\MessageStatusResponse;

test('message() fetches and maps a message status by uuid', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, [
            'success' => true,
            'message_id' => 'msg-uuid-1',
            'channel' => 'sms',
            'recipient' => '998901234567',
            'status' => 'delivered',
            'sms_type' => 'service',
            'price' => '120.0000',
            'currency' => 'UZS',
            'error_code' => null,
            'created_at' => '2026-09-16T10:00:00Z',
            'sent_at' => '2026-09-16T10:00:01Z',
            'delivered_at' => '2026-09-16T10:00:05Z',
        ]),
    ], $history);

    $status = $client->message('msg-uuid-1');

    expect($status)->toBeInstanceOf(MessageStatusResponse::class);
    expect($status->status)->toBe('delivered');
    expect($status->channel)->toBe('sms');
    expect((string) $history[1]['request']->getUri())->toContain('/messages/msg-uuid-1');
    expect($history[1]['request']->getMethod())->toBe('GET');
});

test('a message that does not exist (or belongs to another company) maps to NotFoundException', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Xabar topilmadi.']]),
    ]);

    expect(fn () => $client->message('unknown-uuid'))->toThrow(NotFoundException::class);
});
