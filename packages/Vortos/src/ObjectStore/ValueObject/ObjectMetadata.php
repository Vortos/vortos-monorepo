<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ObjectMetadata
{
    /** @param array<string, string> $metadata */
    public function __construct(
        private readonly ObjectKey $key,
        private readonly int $size,
        private readonly ?ContentType $contentType,
        private readonly ?string $etag,
        private readonly ?\DateTimeImmutable $lastModified,
        private readonly array $metadata = [],
    ) {
        if ($size < 0) {
            throw new \InvalidArgumentException('Object size cannot be negative.');
        }
    }

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function contentType(): ?ContentType
    {
        return $this->contentType;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function lastModified(): ?\DateTimeImmutable
    {
        return $this->lastModified;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
