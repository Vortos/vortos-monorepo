<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PromotionResult
{
    public function __construct(
        private readonly ObjectKey $temporaryKey,
        private readonly ObjectKey $permanentKey,
        private readonly StoredObject $storedObject,
        private readonly bool $temporaryDeleted,
    ) {}

    public function temporaryKey(): ObjectKey
    {
        return $this->temporaryKey;
    }

    public function permanentKey(): ObjectKey
    {
        return $this->permanentKey;
    }

    public function storedObject(): StoredObject
    {
        return $this->storedObject;
    }

    public function temporaryDeleted(): bool
    {
        return $this->temporaryDeleted;
    }
}
