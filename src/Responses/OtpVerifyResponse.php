<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class OtpVerifyResponse
{
    public const INVALID = 'OTP_INVALID';

    public const EXPIRED = 'OTP_EXPIRED';

    public const ATTEMPTS_EXCEEDED = 'OTP_ATTEMPTS_EXCEEDED';

    public const ALREADY_VERIFIED = 'OTP_ALREADY_VERIFIED';

    public function __construct(
        public readonly bool $verified,
        /** pending, verified, expired or failed (attempts exhausted). */
        public readonly string $status,
        /** null when verified; otherwise one of the constants above. */
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        /** How many more tries the user has - 0 once the code is burnt. */
        public readonly int $attemptsLeft = 0,
        public readonly ?string $verifiedAt = null,
    ) {}

    /**
     * Only a wrong code leaves the OTP usable - every other failure means
     * the user needs a new code.
     */
    public function canRetry(): bool
    {
        return ! $this->verified && $this->errorCode === self::INVALID && $this->attemptsLeft > 0;
    }

    /**
     * @param  array<string, mixed>  $data  a 200 body, or the body of a 422 carrying an OTP_* error code
     */
    public static function fromArray(array $data): self
    {
        return new self(
            verified: (bool) ($data['verified'] ?? false),
            status: (string) ($data['status'] ?? 'pending'),
            errorCode: $data['error']['code'] ?? null,
            errorMessage: $data['error']['message'] ?? null,
            attemptsLeft: (int) ($data['attempts_left'] ?? 0),
            verifiedAt: $data['verified_at'] ?? null,
        );
    }
}
