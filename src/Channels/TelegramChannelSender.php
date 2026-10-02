<?php

declare(strict_types=1);

namespace CloudMe\Notify\Channels;

use CloudMe\Notify\Responses\TelegramBindingResponse;

final class TelegramChannelSender extends ChannelSender
{
    /**
     * Telegram cannot message a phone number directly. Send the returned
     * link to the customer (SMS, email, your app) before the first Telegram
     * message; the same phone gets the same link again for 7 days.
     *
     * @param  int|null  $channelAccountId  your own Telegram bot profile from Channels; null = your default bot or the platform bot
     */
    public function bindingLink(string $phone, ?int $channelAccountId = null): TelegramBindingResponse
    {
        return $this->client->createTelegramBinding($phone, $channelAccountId);
    }
}
