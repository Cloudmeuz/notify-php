<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

/**
 * `$notify->telegram()->bindingLink(...)`: send $link to the customer; once
 * they open it and press Start, Telegram messages to $phone are delivered.
 * $bound is true (and $link null) when they already did.
 */
final class TelegramBindingResponse
{
    public function __construct(
        public readonly string $phone,
        public readonly bool $bound,
        public readonly ?string $link,
        /** ISO-8601; the link stays valid until then (7 days). */
        public readonly ?string $expiresAt,
        public readonly string $botUsername,
        public readonly ?int $channelAccountId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phone: (string) $data['phone'],
            bound: (bool) $data['bound'],
            link: $data['link'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            botUsername: (string) $data['bot_username'],
            channelAccountId: isset($data['channel_account_id']) ? (int) $data['channel_account_id'] : null,
        );
    }
}
