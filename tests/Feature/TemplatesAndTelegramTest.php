<?php

use CloudMe\Notify\Responses\TelegramBindingResponse;
use CloudMe\Notify\Responses\TemplateResponse;

test('templates()->list() fetches and maps templates, optionally for one channel', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'templates' => [[
            'id' => 42, 'name' => 'Order ready', 'channel' => 'sms', 'type' => 'company', 'subject' => null,
            'content' => 'Hello {{name}}', 'variables' => ['name'], 'whatsapp_template_name' => null, 'whatsapp_template_language' => null,
        ]]]),
    ], $history);

    $templates = $client->templates()->list('sms');

    expect($templates)->toHaveCount(1)
        ->and($templates[0])->toBeInstanceOf(TemplateResponse::class)
        ->and($templates[0]->id)->toBe(42)
        ->and($templates[0]->variables)->toBe(['name']);
    expect($history[1]['request']->getMethod())->toBe('GET')
        ->and((string) $history[1]['request']->getUri())->toEndWith('/templates?channel=sms');
});

test('telegram()->bindingLink() posts the phone and maps the link', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'phone' => '998901234567', 'bound' => false, 'link' => 'https://t.me/notify_bot?start=abc',
            'expires_at' => '2026-10-09T12:00:00+05:00', 'bot_username' => 'notify_bot', 'channel_account_id' => 7]),
    ], $history);

    $binding = $client->telegram()->bindingLink('+998901234567', channelAccountId: 7);

    expect($binding)->toBeInstanceOf(TelegramBindingResponse::class)
        ->and($binding->bound)->toBeFalse()
        ->and($binding->link)->toBe('https://t.me/notify_bot?start=abc')
        ->and($binding->channelAccountId)->toBe(7);
    expect((string) $history[1]['request']->getUri())->toEndWith('/telegram/bindings')
        ->and(json_decode((string) $history[1]['request']->getBody(), true))->toBe(['phone' => '+998901234567', 'channel_account_id' => 7]);
});

test('an already bound phone has no link', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'phone' => '998901234567', 'bound' => true, 'link' => null, 'expires_at' => null, 'bot_username' => 'notify_bot', 'channel_account_id' => null]),
    ]);

    $binding = $client->telegram()->bindingLink('998901234567');

    expect($binding->bound)->toBeTrue()->and($binding->link)->toBeNull()->and($binding->channelAccountId)->toBeNull();
});
