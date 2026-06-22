<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Storage;

use Vortos\Auth\Scim\Domain\ScimUser;

interface ScimUserStorageInterface
{
    public function findById(string $id): ?ScimUser;

    public function findByExternalId(string $externalId): ?ScimUser;

    public function findByUserName(string $userName): ?ScimUser;

    /**
     * List users with optional filter and pagination.
     *
     * @param string|null $filter SCIM filter expression (simplified: "userName eq "foo"")
     * @return array{resources: ScimUser[], totalResults: int}
     */
    public function list(?string $filter = null, int $startIndex = 1, int $count = 100): array;

    public function save(ScimUser $user): void;

    public function delete(string $id): void;
}
