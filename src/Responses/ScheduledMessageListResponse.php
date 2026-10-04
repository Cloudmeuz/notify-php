<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

final class ScheduledMessageListResponse
{
    /**
     * @param  list<ScheduledMessageResponse>  $items  newest first
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
    ) {}

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $meta = $data['meta'] ?? [];
        $items = array_map(static fn (array $item) => ScheduledMessageResponse::fromArray($item), array_values($data['scheduled_messages'] ?? []));

        return new self(
            items: $items,
            currentPage: (int) ($meta['current_page'] ?? 1),
            lastPage: (int) ($meta['last_page'] ?? 1),
            perPage: (int) ($meta['per_page'] ?? count($items)),
            total: (int) ($meta['total'] ?? count($items)),
        );
    }
}
