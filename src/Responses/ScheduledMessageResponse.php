<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class ScheduledMessageResponse
{
    /**
     * @param  array<string, mixed>|null  $variables
     * @param  list<int>|null  $weekdays
     * @param  list<int>|null  $monthDays
     */
    public function __construct(
        public readonly string $id,
        /** active, paused, completed (a one-time schedule that was sent) or cancelled. */
        public readonly string $status,
        public readonly string $environment,
        public readonly string $channel,
        public readonly ?int $channelAccountId,
        public readonly ?string $smsType,
        public readonly ?string $recipient,
        public readonly ?int $contactGroupId,
        public readonly ?int $templateId,
        public readonly ?array $variables,
        public readonly ?string $message,
        public readonly ?string $subject,
        public readonly ?string $photoUrl,
        /** once or recurring. */
        public readonly string $scheduleType,
        public readonly string $startsAt,
        /** interval, weekly, monthly - null for a one-time schedule. */
        public readonly ?string $recurrenceType,
        public readonly ?int $intervalValue,
        public readonly ?string $intervalUnit,
        public readonly ?array $weekdays,
        public readonly ?array $monthDays,
        /** When it is sent next - null when paused, completed or cancelled. */
        public readonly ?string $nextRunAt,
        public readonly ?string $lastRunAt,
        /** Why the last send failed (e.g. insufficient balance); the schedule still moved on. */
        public readonly ?string $lastError,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isRecurring(): bool
    {
        return $this->scheduleType === 'recurring';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            status: (string) $data['status'],
            environment: (string) ($data['environment'] ?? 'production'),
            channel: (string) $data['channel'],
            channelAccountId: isset($data['channel_account_id']) ? (int) $data['channel_account_id'] : null,
            smsType: $data['sms_type'] ?? null,
            recipient: $data['recipient'] ?? null,
            contactGroupId: isset($data['contact_group_id']) ? (int) $data['contact_group_id'] : null,
            templateId: isset($data['template_id']) ? (int) $data['template_id'] : null,
            variables: $data['variables'] ?? null,
            message: $data['message'] ?? null,
            subject: $data['subject'] ?? null,
            photoUrl: $data['photo_url'] ?? null,
            scheduleType: (string) $data['schedule_type'],
            startsAt: (string) $data['starts_at'],
            recurrenceType: $data['recurrence_type'] ?? null,
            intervalValue: isset($data['interval_value']) ? (int) $data['interval_value'] : null,
            intervalUnit: $data['interval_unit'] ?? null,
            weekdays: $data['weekdays'] ?? null,
            monthDays: $data['month_days'] ?? null,
            nextRunAt: $data['next_run_at'] ?? null,
            lastRunAt: $data['last_run_at'] ?? null,
            lastError: $data['last_error'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }
}
