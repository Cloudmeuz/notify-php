<?php

declare(strict_types=1);

namespace CloudMe\Notify\Reports;

use CloudMe\Notify\NotifyClient;
use DateTimeInterface;

/**
 * `$notify->reports()->daily()` / `->monthly()` - each row is returned as a
 * plain associative array (date/channel/count/amount, or month/channel/
 * count/amount for the monthly rollup) rather than a value object, since the
 * shape is a simple flat aggregate with no further behavior to wrap.
 */
final class ReportsClient
{
    public function __construct(private readonly NotifyClient $client) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function daily(?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->client->getReport('reports/daily', $from, $to);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthly(?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        return $this->client->getReport('reports/monthly', $from, $to);
    }
}
