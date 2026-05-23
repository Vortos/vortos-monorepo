<?php

declare(strict_types=1);

namespace Vortos\Messaging\DeadLetter;

use DateTimeInterface;

interface DeadLetterRepositoryInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchFailed(
        int $limit = 50,
        ?string $transport = null,
        ?string $eventClass = null,
        ?string $id = null,
        bool $orderDesc = false,
        ?DateTimeInterface $failedFrom = null,
        ?DateTimeInterface $failedTo = null,
    ): array;

    public function markReplayed(string $id): void;

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array;
}
