<?php

use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\ServerException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\Responses\ScheduledMessageResponse;
use CloudMe\Notify\ScheduledMessages\Recurrence;

function scheduleBody(array $overrides = []): array
{
    return [
        'id' => 'schedule-uuid-1',
        'status' => 'active',
        'environment' => 'production',
        'channel' => 'sms',
        'channel_account_id' => null,
        'sms_type' => null,
        'recipient' => '998901234567',
        'contact_group_id' => null,
        'template_id' => null,
        'variables' => null,
        'message' => 'Eslatma',
        'subject' => null,
        'photo_url' => null,
        'schedule_type' => 'recurring',
        'starts_at' => '2026-10-05T09:00:00+05:00',
        'recurrence_type' => 'weekly',
        'interval_value' => null,
        'interval_unit' => null,
        'weekdays' => [1, 4],
        'month_days' => null,
        'next_run_at' => '2026-10-05T09:00:00+05:00',
        'last_run_at' => null,
        'last_error' => null,
        'created_at' => '2026-10-04T12:00:00+05:00',
        'updated_at' => '2026-10-04T12:00:00+05:00',
        ...$overrides,
    ];
}

test('scheduledMessages()->create() posts a recurring schedule and maps the response', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(201, ['success' => true, 'scheduled_message' => scheduleBody()]),
    ], $history);

    $schedule = $client->scheduledMessages()->create(
        channel: 'sms',
        startsAt: new DateTimeImmutable('2026-10-05 09:00:00', new DateTimeZone('Asia/Tashkent')),
        recipient: '998901234567',
        message: 'Eslatma',
        recurrence: Recurrence::weekly([1, 4]),
    );

    expect($schedule)->toBeInstanceOf(ScheduledMessageResponse::class);
    expect($schedule->id)->toBe('schedule-uuid-1');
    expect($schedule->isActive())->toBeTrue();
    expect($schedule->isRecurring())->toBeTrue();
    expect($schedule->weekdays)->toBe([1, 4]);

    $request = $history[1]['request'];
    expect($request->getMethod())->toBe('POST');
    expect((string) $request->getUri())->toEndWith('/scheduled-messages');
    expect(json_decode((string) $request->getBody(), true))->toBe([
        'channel' => 'sms',
        'recipient' => '998901234567',
        'message' => 'Eslatma',
        'schedule_type' => 'recurring',
        'starts_at' => '2026-10-05T09:00:00+05:00',
        'recurrence_type' => 'weekly',
        'weekdays' => [1, 4],
    ]);
});

test('without a recurrence the schedule is sent once; an interval maps to its value and unit', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(201, ['success' => true, 'scheduled_message' => scheduleBody()]),
        jsonResponse(200, ['success' => true, 'scheduled_message' => scheduleBody()]),
    ], $history);

    $client->scheduledMessages()->create(channel: 'telegram', startsAt: '2026-10-06T10:00:00+05:00', contactGroupId: 12, message: 'Salom, {{name}}!');
    $client->scheduledMessages()->update('schedule-uuid-1', channel: 'sms', startsAt: '2026-10-06T10:00:00+05:00', recipient: '998901234567', message: 'Yangi', recurrence: Recurrence::every(2, 'hours'));

    expect(json_decode((string) $history[1]['request']->getBody(), true))->toBe([
        'channel' => 'telegram',
        'contact_group_id' => 12,
        'message' => 'Salom, {{name}}!',
        'schedule_type' => 'once',
        'starts_at' => '2026-10-06T10:00:00+05:00',
    ]);

    $update = $history[2]['request'];
    expect($update->getMethod())->toBe('PUT');
    expect((string) $update->getUri())->toEndWith('/scheduled-messages/schedule-uuid-1');
    expect(json_decode((string) $update->getBody(), true))->toMatchArray([
        'schedule_type' => 'recurring',
        'recurrence_type' => 'interval',
        'interval_value' => 2,
        'interval_unit' => 'hours',
    ]);
});

test('create() is not retried on a server error, so a schedule is never created twice', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(503, ['message' => 'down']),
    ], $history);

    expect(fn () => $client->scheduledMessages()->create(channel: 'sms', startsAt: '2026-10-06T10:00:00+05:00', recipient: '998901234567', message: 'x'))
        ->toThrow(ServerException::class);
    expect($history)->toHaveCount(2);
});

test('list() sends the filters and maps the page', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, [
            'success' => true,
            'scheduled_messages' => [scheduleBody(), scheduleBody(['id' => 'schedule-uuid-2'])],
            'meta' => ['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5],
        ]),
    ], $history);

    $page = $client->scheduledMessages()->list(status: 'active', perPage: 2);

    expect($page->items)->toHaveCount(2);
    expect($page->items[1]->id)->toBe('schedule-uuid-2');
    expect($page->total)->toBe(5);
    expect($page->hasMorePages())->toBeTrue();
    expect($history[1]['request']->getUri()->getQuery())->toBe('status=active&page=1&per_page=2');
});

test('pause, resume and cancel post to their action endpoints', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'scheduled_message' => scheduleBody(['status' => 'paused', 'next_run_at' => null])]),
        jsonResponse(200, ['success' => true, 'scheduled_message' => scheduleBody()]),
        jsonResponse(200, ['success' => true, 'scheduled_message' => scheduleBody(['status' => 'cancelled', 'next_run_at' => null])]),
    ], $history);

    expect($client->scheduledMessages()->pause('schedule-uuid-1')->isPaused())->toBeTrue();
    expect($client->scheduledMessages()->resume('schedule-uuid-1')->isActive())->toBeTrue();
    expect($client->scheduledMessages()->cancel('schedule-uuid-1')->status)->toBe('cancelled');

    expect(array_map(fn ($entry) => (string) $entry['request']->getUri()->getPath(), array_slice($history, 1)))->toBe([
        '/api/v1/scheduled-messages/schedule-uuid-1/pause',
        '/api/v1/scheduled-messages/schedule-uuid-1/resume',
        '/api/v1/scheduled-messages/schedule-uuid-1/cancel',
    ]);
});

test('state and lookup errors map to typed exceptions', function () {
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(422, ['success' => false, 'error' => ['code' => 'SCHEDULE_START_PASSED', 'message' => 'passed']]),
        jsonResponse(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'missing']]),
    ]);

    try {
        $client->scheduledMessages()->resume('schedule-uuid-1');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errorCode)->toBe('SCHEDULE_START_PASSED');
    }

    expect(fn () => $client->scheduledMessages()->find('missing'))->toThrow(NotFoundException::class);
});

test('a recurrence rejects values the API would refuse', function () {
    expect(fn () => Recurrence::every(0, 'days'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Recurrence::every(1, 'weeks'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Recurrence::weekly([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => Recurrence::monthly([]))->toThrow(InvalidArgumentException::class);
});
