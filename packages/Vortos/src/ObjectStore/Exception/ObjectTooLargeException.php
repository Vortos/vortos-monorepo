<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Exception;

final class ObjectTooLargeException extends ObjectStoreException
{
    public static function forSize(int $size, int $max): self
    {
        return new self(sprintf('Object size %d bytes exceeds configured maximum %d bytes.', $size, $max));
    }
}
