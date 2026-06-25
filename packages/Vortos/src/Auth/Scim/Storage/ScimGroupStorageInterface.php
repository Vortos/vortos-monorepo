<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimGroup;

interface ScimGroupStorageInterface
{
    public function findById(string $tenantId, string $id): ?ScimGroup;

    public function findByExternalId(string $tenantId, string $externalId): ?ScimGroup;

    /**
     * @return array{resources: ScimGroup[], totalResults: int}
     */
    public function list(string $tenantId, ?string $filter = null, int $startIndex = 1, int $count = 100): array;

    public function save(ScimGroup $group): void;

    public function delete(string $tenantId, string $id): void;
}
