<?php

declare(strict_types=1);

namespace Vortos\Domain\Repository;

/**
 * Immutable result of a keyset-paginated query.
 *
 * Contains the result set, the cursor pointing to the next page,
 * and a flag indicating whether more results exist.
 *
 * The cursor is an opaque string — callers pass it back verbatim
 * to retrieve the next page. Implementations encode whatever fields
 * are needed for efficient keyset lookup (typically last ID + sort field).
 *
 * Never use offset pagination in production — it degrades linearly
 * as the dataset grows. Keyset pagination is O(1) regardless of depth.
 *
 * Usage:
 *   $page = $repository->findPage($criteria, limit: 20);
 *   foreach ($page->items as $item) { ... }
 *   if ($page->hasMore) {
 *       $next = $repository->findPage($criteria, limit: 20, cursor: $page->nextCursor);
 *   }
 */
final readonly class PageResult
{
    public function __construct(
        /** @var array<int, array> The result items for this page */
        public array $items,

        /** Opaque cursor string for fetching the next page. Null if no more pages. */
        public ?string $nextCursor,

        /** Whether more results exist beyond this page. */
        public bool $hasMore,

        /** Total count if requested — expensive, only compute when needed. */
        public ?int $total = null,
    ) {}

    /**
     * Named constructor for an empty result — no items, no next page.
     */
    public static function empty(): self
    {
        return new self(items: [], nextCursor: null, hasMore: false, total: 0);
    }

    /**
     * Named constructor for the last page — has items but no next cursor.
     */
    public static function lastPage(array $items, ?int $total = null): self
    {
        return new self(items: $items, nextCursor: null, hasMore: false, total: $total);
    }
}
