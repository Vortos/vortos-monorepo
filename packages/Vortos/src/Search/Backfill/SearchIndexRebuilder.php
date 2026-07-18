<?php

declare(strict_types=1);

namespace Vortos\Search\Backfill;

use Vortos\Search\Index\SearchIndexWriterInterface;

/**
 * Drives a rebuild from the registered {@see SearchBackfillSourceInterface}s into the index —
 * framework-agnostic core behind the `search:rebuild` command and any scheduled reconcile, so
 * the logic is unit-testable without a console.
 */
final class SearchIndexRebuilder
{
    /** @var array<string, SearchBackfillSourceInterface> */
    private array $sources = [];

    /** @param iterable<SearchBackfillSourceInterface> $sources */
    public function __construct(
        private readonly SearchIndexWriterInterface $writer,
        iterable $sources = [],
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->type()] = $source;
        }
    }

    /** @return list<string> the types that can be rebuilt */
    public function types(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Rebuild one type.
     *
     * @param bool          $fresh    drop the type's existing rows first (requires a tenant to be safe)
     * @param callable|null $onEach   optional progress callback, invoked per document upserted
     *
     * @return int documents indexed
     */
    public function rebuildType(
        string $type,
        ?string $tenantId = null,
        bool $fresh = false,
        ?callable $onEach = null,
    ): int {
        $source = $this->sources[$type] ?? throw new \InvalidArgumentException(
            sprintf('No search backfill source registered for type "%s". Known: %s', $type, implode(', ', $this->types())),
        );

        if ($fresh) {
            if ($tenantId === null) {
                throw new \InvalidArgumentException('Refusing a fresh rebuild across all tenants; pass a tenantId.');
            }
            $this->writer->purgeType($type, $tenantId);
        }

        $count = 0;
        foreach ($source->documents($tenantId) as $document) {
            $this->writer->upsert($document);
            $count++;
            if ($onEach !== null) {
                $onEach($document);
            }
        }

        return $count;
    }

    /**
     * Rebuild every registered type.
     *
     * @return array<string, int> indexed count per type
     */
    public function rebuildAll(?string $tenantId = null, bool $fresh = false, ?callable $onEach = null): array
    {
        $result = [];
        foreach ($this->types() as $type) {
            $result[$type] = $this->rebuildType($type, $tenantId, $fresh, $onEach);
        }

        return $result;
    }
}
