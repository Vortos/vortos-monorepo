<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class StoredObject
{
    public function __construct(
        private readonly ObjectKey $key,
        private readonly ?string $etag,
        private readonly int $size,
        private readonly ?string $versionId = null,
    ) {}

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function versionId(): ?string
    {
        return $this->versionId;
    }
}
