<?php

declare(strict_types=1);

namespace Vortos\Persistence\DependencyInjection;

/**
 * Fluent configuration object for the Vortos persistence layer.
 *
 * Loaded via require in PersistenceExtension::load().
 * Project config lives in config/persistence.php:
 *
 *   return static function(VortosPersistenceConfig $config): void {
 *       $config->frameworkTableMode('schema'); // required — see below
 *   };
 *
 * frameworkTableMode() is required and must be set explicitly. The mode
 * determines how framework-owned tables are named in the database:
 *
 *   'schema' — PostgreSQL schema: tables live in the 'vortos' schema
 *              (vortos.messaging_outbox, vortos.paddle_outbox, etc.)
 *   'prefix' — Underscore prefix: tables live in the default schema
 *              (vortos_messaging_outbox, vortos_paddle_outbox, etc.)
 *
 * This is a structural architecture decision — it must be explicit in config
 * so that any process (web workers, queue workers, CI, migration runners)
 * compiles the container with the same table names regardless of what
 * environment variables happen to be loaded.
 */
final class VortosPersistenceConfig
{
    private string  $writeDsn;
    private string  $readDsn;
    private string  $readDatabase;
    private ?string $frameworkTableMode = null;

    public function __construct()
    {
        $this->writeDsn     = $_ENV['VORTOS_WRITE_DB_DSN'] ?? '';
        $this->readDsn      = $_ENV['VORTOS_READ_DB_DSN'] ?? '';
        $this->readDatabase = $_ENV['VORTOS_READ_DB_NAME'] ?? '';
    }

    /**
     * Set the framework table mode. Required.
     *
     * 'schema' — PostgreSQL: tables live in the vortos schema (vortos.table_name).
     * 'prefix' — All other databases: tables use vortos_ prefix (vortos_table_name).
     */
    public function frameworkTableMode(string $mode): static
    {
        if (!in_array($mode, ['schema', 'prefix'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Framework table mode must be "schema" or "prefix", got "%s".', $mode)
            );
        }

        $this->frameworkTableMode = $mode;
        return $this;
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

    /** @internal Used by PersistenceExtension — not for direct use */
    public function toArray(): array
    {
        return [
            'framework_table_mode' => $this->frameworkTableMode,
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
