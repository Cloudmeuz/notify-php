<?php

declare(strict_types=1);

namespace CloudMe\Notify\Otp;

use CloudMe\Notify\Exceptions\ForbiddenException;
use CloudMe\Notify\Exceptions\InsufficientBalanceException;
use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\RateLimitException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\NotifyClient;
use CloudMe\Notify\Responses\OtpSendResponse;
use CloudMe\Notify\Responses\OtpVerifyResponse;

/**
 * `$notify->otp()->send(...)` / `->verify(...)` - the platform generates,
 * delivers and checks the code; your application never sees or stores it
 * (except in the sandbox, where it is returned for testing).
 *
 * ```php
 * $otp = $notify->otp()->send(to: '998901234567');
 * // store $otp->otpId against the user's session...
 *
 * $result = $notify->otp()->verify($otpId, $codeTheUserTyped);
 * if ($result->verified) { ... }
 * ```
 */
final class OtpClient
{
    public function __construct(private readonly NotifyClient $client) {}

    /**
     * Code length, validity and allowed attempts come from your company's
     * OTP settings (or the platform defaults) and are echoed back on the
     * response.
     *
     * @param  string  $channel  sms (default), telegram, whatsapp or email
     *
     * @throws RateLimitException when a code was sent to this recipient too recently (`retryAfterSeconds` says how long to wait)
     * @throws InsufficientBalanceException
     * @throws ForbiddenException when the API client lacks the channel's send scope
     * @throws ValidationException
     */
    public function send(string $to, string $channel = 'sms'): OtpSendResponse
    {
        return $this->client->sendOtp($to, $channel);
    }

    /**
     * A wrong, expired, burnt or already-used code is a normal outcome, not
     * an exception - check `$result->verified` and, if false,
     * `$result->errorCode` / `$result->attemptsLeft`.
     *
     * @throws NotFoundException when the otp_id is unknown or belongs to another company
     * @throws ValidationException when the request itself is malformed
     */
    public function verify(string $otpId, string $code): OtpVerifyResponse
    {
        return $this->client->verifyOtp($otpId, $code);
    }
}
