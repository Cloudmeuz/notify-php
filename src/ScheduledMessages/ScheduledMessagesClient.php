<?php

declare(strict_types=1);

namespace CloudMe\Notify\ScheduledMessages;

use CloudMe\Notify\Exceptions\ForbiddenException;
use CloudMe\Notify\Exceptions\NotFoundException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\NotifyClient;
use CloudMe\Notify\Responses\ScheduledMessageListResponse;
use CloudMe\Notify\Responses\ScheduledMessageResponse;
use DateTimeInterface;

/**
 * `$notify->scheduledMessages()` - send a message later, once or on a
 * recurring timetable, and edit, pause, resume or cancel it. These are the
 * same schedules as the dashboard's Scheduled messages page. Requires the
 * `schedules:manage` scope plus the channel's send scope (e.g. `sms:send`).
 *
 * ```php
 * // once, tomorrow at 10:00
 * $schedule = $notify->scheduledMessages()->create(
 *     channel: 'sms',
 *     startsAt: new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Asia/Tashkent')),
 *     recipient: '998901234567',
 *     message: 'Eslatma: bugun soat 15:00 da uchrashuv',
 * );
 *
 * // every Monday and Thursday at 09:00, to a contact group
 * $notify->scheduledMessages()->create(
 *     channel: 'telegram',
 *     startsAt: '2026-10-05T09:00:00+05:00',
 *     contactGroupId: 12,
 *     message: 'Salom, {{name}}! Haftalik yangiliklar...',
 *     recurrence: Recurrence::weekly([1, 4]),
 * );
 *
 * $notify->scheduledMessages()->pause($schedule->id);
 * $notify->scheduledMessages()->resume($schedule->id);
 * $notify->scheduledMessages()->cancel($schedule->id);
 * ```
 */
final class ScheduledMessagesClient
{
    public function __construct(private readonly NotifyClient $client) {}

    /**
     * Send either $recipient (one person) or $contactGroupId (a contact group
     * from the dashboard - production only; `{{name}}` in $message becomes
     * each contact's name). Not retried automatically: a lost response could
     * otherwise create the schedule twice.
     *
     * @param  DateTimeInterface|string  $startsAt  once: when to send; recurring: the first send and the time of day of weekly/monthly sends. A string without an offset is read as Asia/Tashkent time.
     * @param  Recurrence|null  $recurrence  null = send once
     * @param  array<string, mixed>  $variables  template variables
     *
     * @throws ValidationException `TEMPLATE_NOT_FOUND`, `CONTACT_GROUP_NOT_FOUND`, `SANDBOX_GROUP_UNSUPPORTED`, or invalid fields
     * @throws ForbiddenException when the API client lacks `schedules:manage` or the channel's send scope
     */
    public function create(
        string $channel,
        DateTimeInterface|string $startsAt,
        ?string $recipient = null,
        ?int $contactGroupId = null,
        ?string $message = null,
        ?int $templateId = null,
        array $variables = [],
        ?Recurrence $recurrence = null,
        ?string $subject = null,
        ?string $smsType = null,
        ?string $photoUrl = null,
        ?int $channelAccountId = null,
    ): ScheduledMessageResponse {
        $response = $this->client->scheduledMessageRequest('POST', 'scheduled-messages', json: $this->body(
            $channel, $startsAt, $recipient, $contactGroupId, $message, $templateId, $variables, $recurrence, $subject, $smsType, $photoUrl, $channelAccountId,
        ));

        return ScheduledMessageResponse::fromArray($response['scheduled_message']);
    }

    /**
     * Replaces the whole schedule - pass everything, as for create(). Clears
     * `lastError` and recomputes `nextRunAt`. Safe to retry.
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws ValidationException `SCHEDULE_STATE_INVALID` (completed or cancelled), `TEMPLATE_NOT_FOUND`, `CONTACT_GROUP_NOT_FOUND`, `SANDBOX_GROUP_UNSUPPORTED`, or invalid fields
     * @throws NotFoundException
     */
    public function update(
        string $scheduledMessageId,
        string $channel,
        DateTimeInterface|string $startsAt,
        ?string $recipient = null,
        ?int $contactGroupId = null,
        ?string $message = null,
        ?int $templateId = null,
        array $variables = [],
        ?Recurrence $recurrence = null,
        ?string $subject = null,
        ?string $smsType = null,
        ?string $photoUrl = null,
        ?int $channelAccountId = null,
    ): ScheduledMessageResponse {
        $response = $this->client->scheduledMessageRequest('PUT', "scheduled-messages/{$scheduledMessageId}", json: $this->body(
            $channel, $startsAt, $recipient, $contactGroupId, $message, $templateId, $variables, $recurrence, $subject, $smsType, $photoUrl, $channelAccountId,
        ), retryable: true);

        return ScheduledMessageResponse::fromArray($response['scheduled_message']);
    }

    /**
     * @throws NotFoundException
     */
    public function find(string $scheduledMessageId): ScheduledMessageResponse
    {
        $response = $this->client->scheduledMessageRequest('GET', "scheduled-messages/{$scheduledMessageId}", retryable: true);

        return ScheduledMessageResponse::fromArray($response['scheduled_message']);
    }

    /**
     * One page of schedules, newest first.
     *
     * @param  string|null  $status  active, paused, completed or cancelled
     * @param  int  $perPage  1-100
     */
    public function list(?string $status = null, ?string $channel = null, int $page = 1, int $perPage = 20): ScheduledMessageListResponse
    {
        $response = $this->client->scheduledMessageRequest('GET', 'scheduled-messages', query: array_filter([
            'status' => $status,
            'channel' => $channel,
            'page' => $page,
            'per_page' => $perPage,
        ], static fn ($value) => $value !== null), retryable: true);

        return ScheduledMessageListResponse::fromArray($response);
    }

    /**
     * Stops sending until resume(). Only an active schedule can be paused.
     *
     * @throws ValidationException `SCHEDULE_STATE_INVALID`
     * @throws NotFoundException
     */
    public function pause(string $scheduledMessageId): ScheduledMessageResponse
    {
        return $this->action($scheduledMessageId, 'pause');
    }

    /**
     * Continues at the next occurrence from now - sends missed while paused
     * are skipped, not sent late.
     *
     * @throws ValidationException `SCHEDULE_STATE_INVALID` (not paused), `SCHEDULE_START_PASSED` (a one-time schedule whose time has passed - update() it instead)
     * @throws NotFoundException
     */
    public function resume(string $scheduledMessageId): ScheduledMessageResponse
    {
        return $this->action($scheduledMessageId, 'resume');
    }

    /**
     * Nothing more is sent; a cancelled schedule cannot be changed or resumed.
     *
     * @throws ValidationException `SCHEDULE_STATE_INVALID` when it is already completed or cancelled
     * @throws NotFoundException
     */
    public function cancel(string $scheduledMessageId): ScheduledMessageResponse
    {
        return $this->action($scheduledMessageId, 'cancel');
    }

    private function action(string $scheduledMessageId, string $action): ScheduledMessageResponse
    {
        $response = $this->client->scheduledMessageRequest('POST', "scheduled-messages/{$scheduledMessageId}/{$action}");

        return ScheduledMessageResponse::fromArray($response['scheduled_message']);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function body(
        string $channel,
        DateTimeInterface|string $startsAt,
        ?string $recipient,
        ?int $contactGroupId,
        ?string $message,
        ?int $templateId,
        array $variables,
        ?Recurrence $recurrence,
        ?string $subject,
        ?string $smsType,
        ?string $photoUrl,
        ?int $channelAccountId,
    ): array {
        return array_filter([
            'channel' => $channel,
            'recipient' => $recipient,
            'contact_group_id' => $contactGroupId,
            'message' => $message,
            'template_id' => $templateId,
            'variables' => $variables === [] ? null : $variables,
            'subject' => $subject,
            'sms_type' => $smsType,
            'photo_url' => $photoUrl,
            'channel_account_id' => $channelAccountId,
            'schedule_type' => $recurrence === null ? 'once' : 'recurring',
            'starts_at' => $startsAt instanceof DateTimeInterface ? $startsAt->format(DateTimeInterface::ATOM) : $startsAt,
            ...($recurrence?->toArray() ?? []),
        ], static fn ($value) => $value !== null);
    }
}
