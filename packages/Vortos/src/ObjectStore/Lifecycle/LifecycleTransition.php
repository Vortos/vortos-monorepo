<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

/**
 * Move objects into another storage class once they reach an age.
 *
 * Age is measured from object creation, not last access: neither R2 nor S3 lifecycle rules can see
 * reads. Choose the day count from how long objects under the prefix are actually read, not from
 * when they were last touched.
 */
final class LifecycleTransition
{
    public function __construct(
        private readonly int $days,
        private readonly ObjectStorageClass $storageClass,
    ) {
        if ($days < 1) {
            throw new ObjectStoreConfigurationException('Lifecycle transition must be at least one day after creation.');
        }
    }

    public function days(): int
    {
        return $this->days;
    }

    public function storageClass(): ObjectStorageClass
    {
        return $this->storageClass;
    }

    public function equals(self $other): bool
    {
        return $this->days === $other->days
            // @phpstan-ignore identical.alwaysTrue (ObjectStorageClass has one case today; this must still compare once a second class exists)
            && $this->storageClass === $other->storageClass;
    }

    /** @return array{Days: int, StorageClass: string} */
    public function toS3(): array
    {
        return ['Days' => $this->days, 'StorageClass' => $this->storageClass->value];
    }
}
