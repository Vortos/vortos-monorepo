<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ObjectListing
{
    /** @param ListedObject[] $objects */
    public function __construct(
        private readonly array $objects,
        private readonly ?string $nextContinuationToken = null,
        private readonly bool $truncated = false,
    ) {}

    /** @return ListedObject[] */
    public function objects(): array
    {
        return $this->objects;
    }

    public function nextContinuationToken(): ?string
    {
        return $this->nextContinuationToken;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
