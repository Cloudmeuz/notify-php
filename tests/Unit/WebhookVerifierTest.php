<?php

use CloudMe\Notify\Exceptions\ConfigurationException;
use CloudMe\Notify\Exceptions\InvalidWebhookSignatureException;
use CloudMe\Notify\Webhooks\WebhookVerifier;

const WEBHOOK_SECRET = 'whsec_test_secret';
const WEBHOOK_NOW = 1790000000;

/**
 * Signs exactly the way the Notify server does (App\Domain\Notification\WebhookSender).
 *
 * @return array<string, string>
 */
function notifyWebhookHeaders(string $body, int $timestamp = WEBHOOK_NOW, string $secret = WEBHOOK_SECRET): array
{
    return [
        'X-Webhook-Id' => 'b5a1c2d3-0000-4000-8000-000000000001',
        'X-Webhook-Event' => 'message.failed',
        'X-Webhook-Timestamp' => (string) $timestamp,
        'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function failedWebhookBody(): string
{
    return json_encode([
        'event' => 'message.failed',
        'message_id' => 'msg-uuid-1',
        'channel' => 'telegram',
        'recipient' => '998901234567',
        'status' => 'failed',
        'environment' => 'production',
        'timestamp' => '2026-10-02T10:00:00+05:00',
        'error_code' => 'CHAT_NOT_BOUND',
        'error_message' => 'Mijoz hali Telegram botni ishga tushirmagan',
    ]);
}

test('a correctly signed webhook is parsed into an event', function () {
    $body = failedWebhookBody();

    $event = (new WebhookVerifier(WEBHOOK_SECRET))->parse($body, notifyWebhookHeaders($body), now: WEBHOOK_NOW + 10);

    expect($event->id)->toBe('b5a1c2d3-0000-4000-8000-000000000001');
    expect($event->event)->toBe('message.failed');
    expect($event->messageId)->toBe('msg-uuid-1');
    expect($event->errorCode)->toBe('CHAT_NOT_BOUND');
    expect($event->signedAt)->toBe(WEBHOOK_NOW);
    expect($event->isTest())->toBeFalse();
});

test('header names are matched case-insensitively and may be arrays', function () {
    $body = failedWebhookBody();
    $headers = array_change_key_case(array_map(fn ($value) => [$value], notifyWebhookHeaders($body)), CASE_LOWER);

    expect((new WebhookVerifier(WEBHOOK_SECRET))->parse($body, $headers, now: WEBHOOK_NOW)->event)->toBe('message.failed');
});

test('a tampered body, wrong secret or missing signature is rejected', function (callable $mutate) {
    $body = failedWebhookBody();
    [$body, $headers] = $mutate($body, notifyWebhookHeaders($body));

    expect(fn () => (new WebhookVerifier(WEBHOOK_SECRET))->parse($body, $headers, now: WEBHOOK_NOW))
        ->toThrow(InvalidWebhookSignatureException::class);
})->with([
    'tampered body' => [fn ($body, $headers) => [str_replace('failed', 'delivered', $body), $headers]],
    'wrong secret' => [fn ($body, $headers) => [$body, notifyWebhookHeaders($body, secret: 'whsec_other')]],
    'missing signature' => [fn ($body, $headers) => [$body, array_diff_key($headers, ['X-Signature' => true])]],
    'signature over the body alone (old scheme)' => [fn ($body, $headers) => [$body, [...$headers, 'X-Signature' => hash_hmac('sha256', $body, WEBHOOK_SECRET)]]],
]);

test('a timestamp outside the tolerance window is rejected as a replay', function () {
    $body = failedWebhookBody();
    $verifier = new WebhookVerifier(WEBHOOK_SECRET, toleranceSeconds: 300);

    expect($verifier->isValid($body, notifyWebhookHeaders($body)['X-Signature'], WEBHOOK_NOW, now: WEBHOOK_NOW + 300))->toBeTrue();
    expect($verifier->isValid($body, notifyWebhookHeaders($body)['X-Signature'], WEBHOOK_NOW, now: WEBHOOK_NOW + 301))->toBeFalse();
});

test('an empty secret is a configuration error', function () {
    new WebhookVerifier('  ');
})->throws(ConfigurationException::class);
