<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class OtpSendResponse
{
    public function __construct(
        /** Keep this - it is what verify() needs. */
        public readonly string $otpId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $status,
        public readonly int $codeLength,
        public readonly int $maxAttempts,
        public readonly ?string $expiresAt,
        public readonly int $expiresIn,
        public readonly string $messageId,
        public readonly string $price,
        public readonly string $currency,
        public readonly ?string $balance,
        /** "production" or "sandbox". */
        public readonly string $environment = 'production',
        /** The generated code - only ever set in the sandbox, for testing verify(). */
        public readonly ?string $code = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            otpId: (string) $data['otp_id'],
            channel: (string) $data['channel'],
            recipient: (string) $data['recipient'],
            status: (string) $data['status'],
            codeLength: (int) $data['code_length'],
            maxAttempts: (int) $data['max_attempts'],
            expiresAt: $data['expires_at'] ?? null,
            expiresIn: (int) ($data['expires_in'] ?? 0),
            messageId: (string) $data['message_id'],
            price: (string) $data['price'],
            currency: (string) $data['currency'],
            balance: isset($data['balance']) ? (string) $data['balance'] : null,
            environment: (string) ($data['environment'] ?? 'production'),
            code: isset($data['code']) ? (string) $data['code'] : null,
        );
    }
}
