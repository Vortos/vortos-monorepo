<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

interface PaddleOutboxRetryStoreInterface
{
    public function countFailed(?int $id = null, ?string $operation = null): int;

    /** @return array<int, array<string, mixed>> */
    public function listFailed(int $limit, ?int $id = null, ?string $operation = null): array;

    public function resetFailed(int $limit, ?int $id = null, ?string $operation = null): int;
}
