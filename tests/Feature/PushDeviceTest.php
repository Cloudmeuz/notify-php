<?php

test('push()->registerDevice() posts the phone and fcm token to push/devices', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true]),
    ], $history);

    $client->push()->registerDevice('998901234567', 'device-token-1');

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toContain('/push/devices');
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'phone' => '998901234567',
        'fcm_token' => 'device-token-1',
    ]);
});

test('push()->send() sends a push notification like any other channel', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '0.00', 'currency' => 'UZS']),
    ], $history);

    $client->push()->send(to: '998901234567', message: 'Your order shipped.', subject: 'Order update');

    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body)->toBe(['to' => '998901234567', 'message' => 'Your order shipped.', 'subject' => 'Order update']);
});
