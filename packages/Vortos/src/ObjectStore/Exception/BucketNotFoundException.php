<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Exception;

final class BucketNotFoundException extends ObjectStoreException
{
    public static function forBucket(string $bucket): self
    {
        return new self(sprintf('Object store bucket not found: %s', $bucket));
    }
}
