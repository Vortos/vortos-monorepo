<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

/**
 * An ordered, already-scoped result set plus its grouping by type — the shape a command palette
 * wants (flat list for keyboard nav; grouped map for section headers).
 */
final class SearchResults implements \JsonSerializable
{
    /** @param list<SearchHit> $hits ranked best-first */
    public function __construct(
        public readonly array $hits,
        public readonly bool $fromCache = false,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    public function withCacheFlag(bool $fromCache): self
    {
        return new self($this->hits, $fromCache);
    }

    /**
     * Hits grouped by type, preserving rank order within each group.
     *
     * @return array<string, list<SearchHit>>
     */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->hits as $hit) {
            $groups[$hit->type][] = $hit;
        }

        return $groups;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'hits'   => $this->hits,
            'groups' => array_keys($this->grouped()),
            'total'  => count($this->hits),
        ];
    }
}
