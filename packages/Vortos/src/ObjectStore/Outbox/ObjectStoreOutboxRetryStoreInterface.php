<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

interface ObjectStoreOutboxRetryStoreInterface
{
    public function countDead(
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): int;

    /** @return array<int, array<string, mixed>> */
    public function listDead(
        int                 $limit,
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): array;

    public function resetDead(
        int                 $limit,
        ?string             $id          = null,
        ?string             $operation   = null,
        ?\DateTimeImmutable $createdFrom = null,
        ?\DateTimeImmutable $createdTo   = null,
    ): int;
}
