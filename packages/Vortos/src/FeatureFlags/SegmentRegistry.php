<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

/**
 * Request-scoped segment resolver. Bulk-loads every segment on first access into a
 * `name → Segment` map and serves all subsequent lookups from memory — so a segment
 * referenced by N flags costs **one** query, not N (PLATFORM §3, no N×M trap).
 *
 * Block 11: When a {@see ProjectContext} is wired in, only segments belonging to the
 * active project are resolved. Filtering is done in PHP after bulk load.
 */
final class SegmentRegistry implements SegmentResolverInterface, ResetInterface
{
    /** @var array<string,Segment>|null */
    private ?array $byName = null;
    private ?string $memoProject = null;

    public function __construct(
        private readonly SegmentStorageInterface $storage,
        private readonly ?ProjectContext $projectContext = null,
    ) {}

    public function resolve(string $name): ?Segment
    {
        $project = $this->projectContext?->projectId() ?? ProjectContext::DEFAULT_PROJECT;

        if ($this->byName === null || $this->memoProject !== $project) {
            $this->byName      = [];
            $this->memoProject = $project;
            foreach ($this->storage->findAll() as $segment) {
                if ($segment->projectId === $project) {
                    $this->byName[$segment->name] = $segment;
                }
            }
        }

        return $this->byName[$name] ?? null;
    }

    public function reset(): void
    {
        $this->byName      = null;
        $this->memoProject = null;
    }
}
