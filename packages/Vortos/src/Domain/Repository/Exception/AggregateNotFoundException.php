<?php

declare(strict_types=1);

namespace Vortos\Domain\Repository\Exception;

/**
 * Thrown when an aggregate cannot be found by its ID.
 *
 * Distinct from OptimisticLockException — this means the aggregate does not
 * exist in the store at all, not that it was concurrently modified.
 *
 * The write repository throws this from delete() when the DELETE query
 * affects zero rows and a follow-up existence check confirms the row is gone.
 */
final class AggregateNotFoundException extends \RuntimeException
{
    /**
     * @param class-string $aggregateClass
     */
    public static function for(string $aggregateClass, string $aggregateId): self
    {
        return new self(sprintf(
            '%s#%s does not exist.',
            $aggregateClass,
            $aggregateId,
        ));
    }
}
