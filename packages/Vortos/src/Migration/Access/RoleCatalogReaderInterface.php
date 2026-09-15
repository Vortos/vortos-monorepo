<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Persistence\Access\DatabaseRoleModel;

interface RoleCatalogReaderInterface
{
    /**
     * Reads the catalog scoped to the model's schemas and roles. Every catalog it touches is readable by any
     * role, so the same reader serves the runtime, backup and owner connections.
     *
     * @throws \Throwable when the catalog cannot be read
     */
    public function read(DatabaseRoleModel $model): RoleCatalogSnapshot;
}
