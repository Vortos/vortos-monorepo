<?php

namespace Vortos\Domain\Repository\Exception;

/**
 * Thrown when a concurrent modification is detected during aggregate save.
 *
 * The write repository issues UPDATE ... WHERE version = $expectedVersion.
 * If zero rows are affected, another process modified the aggregate first.
 * The application layer should catch this and either retry the command
 * or return a conflict response to the caller.
 *
 * This is a domain exception — it expresses a business-level conflict,
 * not a database error. Infrastructure throws it, domain catches it.
 */
final class OptimisticLockException extends \RuntimeException
{
    /**
     * @param class-string $aggregateClass
     */
    public static function forAggregate(
        string $aggregateClass,
        string $aggregateId,
        int $expectedVersion,
        int $actualVersion,
    ): self {
        return new self(sprintf(
            'Optimistic lock conflict on %s#%s: expected version %d but found %d. ' .
                'Another process modified this aggregate concurrently.',
            $aggregateClass,
            $aggregateId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
