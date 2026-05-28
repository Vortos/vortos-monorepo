<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class BulkDeleteResult
{
    /** @param DeleteResult[] $results */
    public function __construct(private readonly array $results)
    {
    }

    /** @return DeleteResult[] */
    public function results(): array
    {
        return $this->results;
    }

    public function deletedCount(): int
    {
        return count(array_filter($this->results, static fn(DeleteResult $r): bool => $r->deleted()));
    }
}
