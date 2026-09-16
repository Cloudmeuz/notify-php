<?php

use CloudMe\Notify\Auth\ArrayTokenStorage;
use CloudMe\Notify\Exceptions\AuthenticationException;
use CloudMe\Notify\NotifyClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

test('a send acquires a token first, then reuses it for the second send instead of re-authenticating', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS', 'balance' => '999880.00']),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-2', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS', 'balance' => '999760.00']),
    ], $history);

    $client->sms()->send(to: '998901234567', message: 'first');
    $client->sms()->send(to: '998901234567', message: 'second');

    expect($history)->toHaveCount(3);
    expect((string) $history[0]['request']->getUri())->toContain('oauth/token');
    expect((string) $history[1]['request']->getUri())->toContain('messages/sms');
    expect((string) $history[2]['request']->getUri())->toContain('messages/sms');

    $tokenRequestBody = json_decode((string) $history[0]['request']->getBody(), true);
    expect($tokenRequestBody)->toHaveKeys(['client_id', 'api_key', 'timestamp', 'nonce', 'signature']);
    expect($tokenRequestBody['client_id'])->toBe('test-client-id');

    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer access-token-1');
    expect($history[2]['request']->getHeaderLine('Authorization'))->toBe('Bearer access-token-1');
});

test('a near-expired access token is refreshed via the refresh token instead of a full re-authentication', function () {
    $storage = new ArrayTokenStorage;
    $storage->put([
        'access_token' => 'stale-access-token',
        'access_token_expires_at' => time() + 5, // inside the 30s skew window
        'refresh_token' => 'still-valid-refresh-token',
        'refresh_token_expires_at' => time() + 86400,
    ]);

    // makeMockedClient() doesn't accept a custom TokenStorage (NotifyClient
    // takes it as its own constructor argument, not via $options), so this
    // one is built directly to exercise the real refresh path.
    $history = [];
    $client = new NotifyClient(
        clientId: 'test-client-id',
        apiKey: 'test-api-key',
        privateKey: generateTestRsaKeyPair()[0],
        baseUrl: 'https://notify.test/api/v1',
        tokenStorage: $storage,
        options: ['handler' => (function () use (&$history) {
            $mock = new MockHandler([
                jsonResponse(200, tokenResponseBody(accessToken: 'refreshed-access-token')),
                jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS', 'balance' => '999880.00']),
            ]);
            $stack = HandlerStack::create($mock);
            $stack->push(Middleware::history($history));

            return $stack;
        })(), 'max_retries' => 2],
    );

    $client->sms()->send(to: '998901234567', message: 'hi');

    // A refresh (not a full re-authentication) followed by the actual send -
    // no oauth/token call at all, since the cached refresh token was reused.
    expect($history)->toHaveCount(2);
    expect((string) $history[0]['request']->getUri())->toContain('oauth/refresh');
    expect((string) $history[1]['request']->getUri())->toContain('messages/sms');
    $refreshBody = json_decode((string) $history[0]['request']->getBody(), true);
    expect($refreshBody['refresh_token'])->toBe('still-valid-refresh-token');
    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer refreshed-access-token');
});

test('a 401 from a business endpoint triggers exactly one forced re-authentication and retry', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(401, ['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Access token yaroqsiz yoki muddati tugagan.']]),
        jsonResponse(200, tokenResponseBody(accessToken: 'fresh-access-token')),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'queued', 'price' => '120.00', 'currency' => 'UZS', 'balance' => '999880.00']),
    ], $history);

    $response = $client->sms()->send(to: '998901234567', message: 'hi');

    expect($response->messageId)->toBe('msg-1');
    expect($history)->toHaveCount(4);
    expect((string) $history[0]['request']->getUri())->toContain('oauth/token');
    expect((string) $history[1]['request']->getUri())->toContain('messages/sms');
    expect((string) $history[2]['request']->getUri())->toContain('oauth/token');
    expect((string) $history[3]['request']->getUri())->toContain('messages/sms');
    expect($history[3]['request']->getHeaderLine('Authorization'))->toBe('Bearer fresh-access-token');
});

test('a hard authentication failure (bad signature) surfaces as AuthenticationException', function () {
    $client = makeMockedClient([
        jsonResponse(401, ['success' => false, 'error' => ['code' => 'SIGNATURE_INVALID', 'message' => "Signature tekshiruvidan o'tmadi."]]),
    ]);

    expect(fn () => $client->balance())->toThrow(AuthenticationException::class);
});
