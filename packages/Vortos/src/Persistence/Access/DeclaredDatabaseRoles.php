<?php

declare(strict_types=1);

namespace Vortos\Persistence\Access;

/**
 * The {@see DatabaseRoleModel} config/persistence.php declares, or null when it declares none.
 *
 * A service rather than a container parameter so every package that governs or checks database roles — the
 * inspector, the deploy preflight, the alert-coverage gate — reads one declaration by type, and a package that
 * is not installed alongside persistence simply finds no service instead of a missing parameter.
 */
final class DeclaredDatabaseRoles
{
    /** @param array<mixed>|null $declaration {@see DatabaseRoleModel::toArray()} */
    public function __construct(private readonly ?array $declaration) {}

    public function model(): ?DatabaseRoleModel
    {
        return $this->declaration === null ? null : DatabaseRoleModel::fromArray($this->declaration);
    }
}
