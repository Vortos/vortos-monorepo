<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ListedObject
{
    public function __construct(
        private readonly ObjectKey $key,
        private readonly int $size,
        private readonly ?string $etag = null,
        private readonly ?\DateTimeImmutable $lastModified = null,
    ) {}

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function lastModified(): ?\DateTimeImmutable
    {
        return $this->lastModified;
    }
}
