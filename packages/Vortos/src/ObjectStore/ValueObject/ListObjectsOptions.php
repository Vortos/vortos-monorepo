<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class ListObjectsOptions
{
    public function __construct(
        private readonly ?string $prefix = null,
        private readonly ?string $delimiter = null,
        private readonly ?string $continuationToken = null,
        private readonly int $maxKeys = 1000,
    ) {
        if ($maxKeys < 1 || $maxKeys > 1000) {
            throw new \InvalidArgumentException('List maxKeys must be between 1 and 1000.');
        }
    }

    public function prefix(): ?string
    {
        return $this->prefix;
    }

    public function delimiter(): ?string
    {
        return $this->delimiter;
    }

    public function continuationToken(): ?string
    {
        return $this->continuationToken;
    }

    public function maxKeys(): int
    {
        return $this->maxKeys;
    }
}
