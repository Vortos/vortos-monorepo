<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimUser;

interface ScimUserStorageInterface
{
    public function findById(string $tenantId, string $id): ?ScimUser;

    public function findByExternalId(string $tenantId, string $externalId): ?ScimUser;

    public function findByUserName(string $tenantId, string $userName): ?ScimUser;

    /**
     * List users within a tenant with optional filter and pagination.
     *
     * @param string|null $filter SCIM filter expression (simplified: "userName eq "foo"")
     * @return array{resources: ScimUser[], totalResults: int}
     */
    public function list(string $tenantId, ?string $filter = null, int $startIndex = 1, int $count = 100): array;

    public function save(ScimUser $user): void;

    public function delete(string $tenantId, string $id): void;
}
