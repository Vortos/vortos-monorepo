<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class DeleteResult
{
    public function __construct(
        private readonly ObjectKey $key,
        private readonly bool $deleted,
    ) {}

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function deleted(): bool
    {
        return $this->deleted;
    }
}
