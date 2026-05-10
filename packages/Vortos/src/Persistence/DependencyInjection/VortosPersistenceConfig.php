<?php

declare(strict_types=1);

namespace Vortos\Persistence\DependencyInjection;

/**
 * Fluent configuration object for the Vortos persistence layer.
 *
 * Loaded via require in PersistenceExtension::load().
 * Project config can override persistence in config/persistence.php:
 *
 *   return static function(VortosPersistenceConfig $config): void {
 *       $config
 *           ->writeDsn('pgsql://postgres:secret@write_db:5432/app')
 *           ->readDsn('mongodb://root:secret@read_db:27017')
 *           ->readDatabase('app');
 *   };
 *
 * By default, framework setup writes VORTOS_WRITE_DB_DSN,
 * VORTOS_READ_DB_DSN, and VORTOS_READ_DB_NAME to .env.
 */
final class VortosPersistenceConfig
{
    private string $writeDsn;
    private string $readDsn;
    private string $readDatabase;

    public function __construct()
    {
        $this->writeDsn = $_ENV['VORTOS_WRITE_DB_DSN'] ?? '';
        $this->readDsn = $_ENV['VORTOS_READ_DB_DSN'] ?? '';
        $this->readDatabase = $_ENV['VORTOS_READ_DB_NAME'] ?? '';
    }

    /**
     * DSN for the write database.
     */
    public function writeDsn(string $dsn): static
    {
        $this->writeDsn = $dsn;
        return $this;
    }

    /**
     * DSN for the read database.
     */
    public function readDsn(string $dsn): static
    {
        $this->readDsn = $dsn;
        return $this;
    }

    /**
     * The read database name to use for read repositories.
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
