<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationOwnership;

interface UserMigrationOwnershipExtractorInterface
{
    public function extract(string $migrationClass): ?MigrationOwnership;
}
