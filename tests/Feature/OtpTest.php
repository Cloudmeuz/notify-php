<?php

use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\RateLimitException;
use CloudMe\Notify\Exceptions\ServerException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\Responses\OtpSendResponse;
use CloudMe\Notify\Responses\OtpVerifyResponse;

function otpSendBody(array $overrides = []): array
{
    return array_merge([
        'success' => true,
        'otp_id' => 'otp-uuid-1',
        'environment' => 'production',
        'channel' => 'sms',
        'recipient' => '998901234567',
        'status' => 'pending',
        'code_length' => 6,
        'max_attempts' => 3,
        'expires_at' => '2026-09-27T10:05:00.000000Z',
        'expires_in' => 300,
        'message_id' => 'msg-uuid-1',
        'price' => '120.0000',
        'currency' => 'UZS',
        'balance' => '999880.00',
    ], $overrides);
}

test('otp()->send() posts to otp/send and maps the response', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, otpSendBody()),
    ], $history);

    $otp = $client->otp()->send(to: '998901234567');

    expect($otp)->toBeInstanceOf(OtpSendResponse::class)
        ->and($otp->otpId)->toBe('otp-uuid-1')
        ->and($otp->codeLength)->toBe(6)
        ->and($otp->maxAttempts)->toBe(3)
        ->and($otp->expiresIn)->toBe(300)
        ->and($otp->code)->toBeNull();

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toContain('/otp/send')
        ->and(json_decode((string) $request->getBody(), true))->toBe(['to' => '998901234567', 'channel' => 'sms']);
});

test('the sandbox code is exposed on the send response', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, otpSendBody(['environment' => 'sandbox', 'code' => '481201'])),
    ]);

    expect($client->otp()->send(to: '998901234567', channel: 'telegram')->code)->toBe('481201');
});

test('otp send is never retried, so a lost response cannot deliver a second code', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(503, ['message' => 'Service Unavailable']),
        jsonResponse(200, otpSendBody()),
    ], $history);

    expect(fn () => $client->otp()->send(to: '998901234567'))->toThrow(ServerException::class);
    expect($history)->toHaveCount(2);
});

test('the resend cooldown surfaces as a rate limit with the wait time', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(429, ['success' => false, 'retry_after' => 42, 'error' => ['code' => 'OTP_RESEND_TOO_SOON', 'message' => 'Wait']], ['Retry-After' => '42']),
    ]);

    try {
        $client->otp()->send(to: '998901234567');
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->errorCode)->toBe('OTP_RESEND_TOO_SOON')
            ->and($e->retryAfterSeconds)->toBe(42);
    }
});

test('a correct code verifies', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'verified' => true, 'otp_id' => 'otp-uuid-1', 'status' => 'verified', 'verified_at' => '2026-09-27T10:01:00.000000Z']),
    ], $history);

    $result = $client->otp()->verify('otp-uuid-1', '481201');

    expect($result)->toBeInstanceOf(OtpVerifyResponse::class)
        ->and($result->verified)->toBeTrue()
        ->and($result->status)->toBe('verified')
        ->and($result->errorCode)->toBeNull();
    expect(json_decode((string) $history[1]['request']->getBody(), true))->toBe(['otp_id' => 'otp-uuid-1', 'code' => '481201']);
});

test('a wrong code is returned as a result with the attempts left, not thrown', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['success' => false, 'verified' => false, 'status' => 'pending', 'attempts_left' => 2, 'error' => ['code' => 'OTP_INVALID', 'message' => "Kod noto'g'ri."]]),
    ]);

    $result = $client->otp()->verify('otp-uuid-1', '000000');

    expect($result->verified)->toBeFalse()
        ->and($result->errorCode)->toBe(OtpVerifyResponse::INVALID)
        ->and($result->attemptsLeft)->toBe(2)
        ->and($result->canRetry())->toBeTrue();
});

test('an expired code cannot be retried', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['success' => false, 'verified' => false, 'status' => 'expired', 'attempts_left' => 0, 'error' => ['code' => 'OTP_EXPIRED', 'message' => 'Expired']]),
    ]);

    $result = $client->otp()->verify('otp-uuid-1', '481201');

    expect($result->errorCode)->toBe(OtpVerifyResponse::EXPIRED)
        ->and($result->canRetry())->toBeFalse();
});

test('a malformed verify request still throws a validation exception', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['message' => 'The otp id field must be a valid UUID.', 'errors' => ['otp_id' => ['The otp id field must be a valid UUID.']]]),
    ]);

    expect(fn () => $client->otp()->verify('not-a-uuid', '1'))->toThrow(ValidationException::class);
});

test('an unknown otp id throws not found', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(404, ['success' => false, 'error' => ['code' => 'OTP_NOT_FOUND', 'message' => 'OTP topilmadi.']]),
    ]);

    expect(fn () => $client->otp()->verify('otp-uuid-x', '481201'))->toThrow(NotFoundException::class);
});

test('a message held for moderation is recognisable on the send response', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'message_id' => 'msg-1', 'status' => 'moderation', 'price' => '120.00', 'currency' => 'UZS']),
    ]);

    $response = $client->sms()->send(to: '998901234567', message: 'Aksiya!');

    expect($response->isHeldForModeration())->toBeTrue()
        ->and($response->isRejected())->toBeFalse();
});
