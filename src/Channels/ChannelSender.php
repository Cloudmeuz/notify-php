<?php

declare(strict_types=1);

namespace CloudMe\Notify\Channels;

use CloudMe\Notify\NotifyClient;
use CloudMe\Notify\Responses\SendMessageResponse;

/**
 * Fluent per-channel sender returned by NotifyClient::sms(), ::telegram(),
 * ::whatsapp(), ::voice(), ::email() - e.g. `$notify->sms()->send(...)`.
 */
class ChannelSender
{
    public function __construct(
        protected readonly NotifyClient $client,
        protected readonly string $channel,
    ) {}

    /**
     * @param  array<string, mixed>  $variables  values to substitute into a template's {{placeholders}} - ignored unless $templateId is given
     */
    public function send(
        string $to,
        ?string $message = null,
        ?int $templateId = null,
        array $variables = [],
        ?string $smsType = null,
        ?string $subject = null,
        ?string $idempotencyKey = null,
    ): SendMessageResponse {
        return $this->client->sendMessage(
            $this->channel,
            $to,
            $message,
            $templateId,
            $variables,
            $smsType,
            $subject,
            $idempotencyKey,
        );
    }

    /**
     * Alias for send() that reads naturally for the voice channel:
     * `$notify->voice()->call(to: '...', message: 'Text to speak')`.
     *
     * @param  array<string, mixed>  $variables
     */
    public function call(
        string $to,
        ?string $message = null,
        ?int $templateId = null,
        array $variables = [],
        ?string $subject = null,
        ?string $idempotencyKey = null,
    ): SendMessageResponse {
        return $this->send($to, $message, $templateId, $variables, null, $subject, $idempotencyKey);
    }
}
