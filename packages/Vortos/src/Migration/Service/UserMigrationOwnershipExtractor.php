<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Vortos\Migration\Schema\MigrationOwnership;

/**
 * Extracts schema ownership from a user-authored migration by running its up()
 * against a RecordingSchema instead of the real database.
 *
 * Returns null when the migration uses raw addSql() — those cannot be intercepted.
 */
final class UserMigrationOwnershipExtractor implements UserMigrationOwnershipExtractorInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function extract(string $migrationClass): ?MigrationOwnership
    {
        if (!is_subclass_of($migrationClass, AbstractMigration::class)) {
            return null;
        }

        try {
            $migration = new $migrationClass($this->connection, new NullLogger());
            $recording = new RecordingSchema();
            $migration->up($recording);

            if (!$recording->hasCapturedObjects()) {
                return null;
            }

            return $recording->capturedOwnership();
        } catch (\Throwable) {
            return null;
        }
    }
}
