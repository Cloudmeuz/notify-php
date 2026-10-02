<?php

declare(strict_types=1);

namespace CloudMe\Notify\Channels;

final class PushChannelSender extends ChannelSender
{
    /**
     * Registers (or refreshes) the FCM device token for one of your
     * customers, identified by phone number - call this whenever your own
     * mobile app obtains or rotates a token for a logged-in user, before
     * sending them a push notification with send().
     */
    public function registerDevice(string $phone, string $fcmToken, ?int $channelAccountId = null): void
    {
        $this->client->registerPushDevice($phone, $fcmToken, $channelAccountId);
    }
}
