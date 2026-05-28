<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Exception;

final class ObjectNotFoundException extends ObjectStoreException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Object not found: %s', $key));
    }
}
