<?php

declare(strict_types=1);

namespace CloudMe\Notify\ScheduledMessages;

use InvalidArgumentException;

/**
 * How a scheduled message repeats. Weekly and monthly sends happen at the
 * time of day of the schedule's `startsAt`.
 *
 * ```php
 * Recurrence::every(2, 'hours');   // every 2 hours from startsAt
 * Recurrence::every(1, 'days');    // daily
 * Recurrence::weekly([1, 3, 5]);   // Monday, Wednesday, Friday (ISO: 1 = Monday ... 7 = Sunday)
 * Recurrence::monthly([1, 15]);    // 1st and 15th of every month
 * ```
 */
final class Recurrence
{
    /**
     * @param  list<int>|null  $days  weekdays (weekly) or days of the month (monthly)
     */
    private function __construct(
        public readonly string $type,
        public readonly ?int $intervalValue = null,
        public readonly ?string $intervalUnit = null,
        public readonly ?array $days = null,
    ) {}

    /**
     * @param  'hours'|'days'  $unit
     */
    public static function every(int $value, string $unit): self
    {
        if ($value < 1 || ! in_array($unit, ['hours', 'days'], true)) {
            throw new InvalidArgumentException('Recurrence::every() takes a positive value and "hours" or "days".');
        }

        return new self('interval', $value, $unit);
    }

    /**
     * @param  list<int>  $weekdays  ISO weekdays, 1 = Monday ... 7 = Sunday
     */
    public static function weekly(array $weekdays): self
    {
        if ($weekdays === []) {
            throw new InvalidArgumentException('Recurrence::weekly() needs at least one weekday.');
        }

        return new self('weekly', days: array_values($weekdays));
    }

    /**
     * @param  list<int>  $monthDays  1-31; a day a month doesn't have is skipped that month
     */
    public static function monthly(array $monthDays): self
    {
        if ($monthDays === []) {
            throw new InvalidArgumentException('Recurrence::monthly() needs at least one day of the month.');
        }

        return new self('monthly', days: array_values($monthDays));
    }

    /**
     * @internal the request fields this recurrence maps to
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            'interval' => ['recurrence_type' => 'interval', 'interval_value' => $this->intervalValue, 'interval_unit' => $this->intervalUnit],
            'weekly' => ['recurrence_type' => 'weekly', 'weekdays' => $this->days],
            default => ['recurrence_type' => 'monthly', 'month_days' => $this->days],
        };
    }
}
