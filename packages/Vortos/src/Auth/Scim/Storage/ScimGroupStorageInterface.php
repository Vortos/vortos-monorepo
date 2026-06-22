<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimGroup;

interface ScimGroupStorageInterface
{
    public function findById(string $id): ?ScimGroup;

    public function findByExternalId(string $externalId): ?ScimGroup;

    /**
     * @return array{resources: ScimGroup[], totalResults: int}
     */
    public function list(?string $filter = null, int $startIndex = 1, int $count = 100): array;

    public function save(ScimGroup $group): void;

    public function delete(string $id): void;
}
