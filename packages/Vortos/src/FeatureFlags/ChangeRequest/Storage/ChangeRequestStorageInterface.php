<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Storage;

use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;

interface ChangeRequestStorageInterface
{
    public function save(ChangeRequest $request): void;

    public function findById(string $id): ?ChangeRequest;

    /** @return ChangeRequest[] */
    public function findDueForApplication(): array;

    /** @return ChangeRequest[] */
    public function findExpired(): array;

    /**
     * @return ChangeRequest[]
     */
    public function findByFlag(
        string $flagName,
        string $projectId,
        string $environment,
        ?ChangeRequestStatus $status = null,
        ?string $afterCursor = null,
        int $limit = 0,
    ): array;
}
