<?php

declare(strict_types=1);

namespace Vortos\Persistence\DependencyInjection;

/**
 * Fluent configuration object for the Vortos persistence layer.
 *
 * Loaded via require in PersistenceExtension::load().
 * Users configure persistence in config/persistence.php:
 *
 *   return static function(VortosPersistenceConfig $config): void {
 *       $config
 *           ->writeDsn($_ENV['DATABASE_URL'])
 *           ->readDsn($_ENV['MONGODB_URL'])
 *           ->readDatabase($_ENV['MONGO_DB_NAME']);
 *   };
 *
 * Environment-specific overrides in config/{env}/persistence.php:
 *
 *   return static function(VortosPersistenceConfig $config): void {
 *       $config->writeDsn('pgsql://postgres:test@write_db:5432/squaura_test');
 *   };
 *
 * All values are required — the extension will throw if any are empty.
 * Never hardcode credentials here — always read from environment variables.
 */
final class VortosPersistenceConfig
{
    private string $writeDsn = '';
    private string $readDsn = '';
    private string $readDatabase = '';

    /**
     * DSN for the write database (PostgreSQL).
     *
     * Format: pgsql://user:pass@host:port/dbname
     * Reads from DATABASE_URL environment variable by convention.
     */
    public function writeDsn(string $dsn): static
    {
        $this->writeDsn = $dsn;
        return $this;
    }

    /**
     * DSN for the read database (MongoDB).
     *
     * Format: mongodb://user:pass@host:port
     * Reads from MONGODB_URL environment variable by convention.
     * Do not include the database name in the DSN — use readDatabase() instead.
     */
    public function readDsn(string $dsn): static
    {
        $this->readDsn = $dsn;
        return $this;
    }

    /**
     * The MongoDB database name to use for all read repositories.
     *
     * Kept separate from the DSN so it can be overridden per environment
     * without changing the connection string.
     *
     * Reads from MONGO_DB_NAME environment variable by convention.
     */
    public function readDatabase(string $name): static
    {
        $this->readDatabase = $name;
        return $this;
    }

    /**
     * Serialize config to array for Symfony Config component validation.
     *
     * @internal Used by PersistenceExtension — not for direct use
     */
    public function toArray(): array
    {
        return [
            'write' => [
                'dsn' => $this->writeDsn,
            ],
            'read' => [
                'dsn'      => $this->readDsn,
                'database' => $this->readDatabase,
            ],
        ];
    }
}
