<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * The outcome of {@see UnpublishedStubDetector::detect()}: the set of installed vortos/* migration
 * stubs (SQL or schema-provider) that have **no** corresponding entry in the app's publish manifest
 * (`migrations/.vortos-published.json`) — i.e. `vortos:migrate:publish` would emit a migration for
 * each of them, and their schema has therefore not been applied.
 *
 * A framework bump that adds a stub ships schema-dependent runtime code without the schema; this
 * report is what turns that latent runtime crash into a preflight failure.
 */
final readonly class UnpublishedStubReport
{
    /**
     * @param list<array{module: string, filename: string, relative: string}> $unpublished
     */
    public function __construct(
        public array $unpublished,
    ) {
    }

    public function hasUnpublished(): bool
    {
        return $this->unpublished !== [];
    }

    public function count(): int
    {
        return count($this->unpublished);
    }

    /**
     * Human-readable `Module/filename` labels, sorted, for surfacing in doctor/status output.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = array_map(
            static fn (array $s): string => $s['module'] . '/' . $s['filename'],
            $this->unpublished,
        );
        sort($labels);

        return $labels;
    }
}
