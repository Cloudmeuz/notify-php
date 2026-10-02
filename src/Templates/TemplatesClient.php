<?php

declare(strict_types=1);

namespace CloudMe\Notify\Templates;

use CloudMe\Notify\NotifyClient;
use CloudMe\Notify\Responses\TemplateResponse;

/**
 * `$notify->templates()->list()` - the templates you can send with: system
 * ones plus your company's approved ones, for the channels your API client
 * has a `{channel}:send` scope for.
 */
final class TemplatesClient
{
    public function __construct(private readonly NotifyClient $client) {}

    /**
     * @param  string|null  $channel  only this channel's templates, e.g. "sms"
     * @return list<TemplateResponse>
     */
    public function list(?string $channel = null): array
    {
        return $this->client->listTemplates($channel);
    }
}
