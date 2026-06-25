<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

interface PendingMigrationVersionProviderInterface
{
    /** @return list<string> */
    public function getPending(): array;

    /** @return list<string> */
    public function getAll(): array;
}
