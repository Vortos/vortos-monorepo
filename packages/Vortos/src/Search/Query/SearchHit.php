<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

/**
 * One result row: everything the UI needs to render it and navigate to it, and nothing the
 * caller isn't allowed to see (the reader already applied the scope). {@see $deeplink} is the
 * clickable target, resolved when the row was indexed.
 */
final class SearchHit implements \JsonSerializable
{
    /**
     * @param array<string,scalar|null> $meta app payload stored at index time (icon/status hints)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $entityId,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $deeplink,
        public readonly float $score,
        public readonly array $meta = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type'     => $this->type,
            'id'       => $this->entityId,
            'title'    => $this->title,
            'subtitle' => $this->subtitle,
            'deeplink' => $this->deeplink,
            'score'    => $this->score,
            'meta'     => $this->meta,
        ];
    }
}
