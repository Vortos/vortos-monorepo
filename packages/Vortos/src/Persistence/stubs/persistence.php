<?php

declare(strict_types=1);

use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

// The persistence DSNs default to ENV vars written by vortos:setup:
//   VORTOS_WRITE_DB_DSN   — write database connection string
//   VORTOS_READ_DB_DSN    — read database connection string
//   VORTOS_READ_DB_NAME   — read database name (MongoDB only)
//
// For per-environment overrides create config/{env}/persistence.php.

return static function (VortosPersistenceConfig $config): void {
    // Framework table mode — required.
    //
    // Determines how framework-owned tables are named in your database.
    // This must be set explicitly so that every process — web workers, queue
    // workers, CI pipelines, migration runners — always compiles the container
    // with the same table names, regardless of which environment variables
    // happen to be loaded.
    //
    //   'schema' — PostgreSQL: tables live in the vortos schema
    //              (vortos.messaging_outbox, vortos.paddle_outbox, etc.)
    //   'prefix' — All other databases: tables use the vortos_ prefix
    //              (vortos_messaging_outbox, vortos_paddle_outbox, etc.)
    //
    $config->frameworkTableMode('schema'); // change to 'prefix' for non-PostgreSQL

    // Write database DSN — PostgreSQL (DBAL) or compatible.
    // Format: pgsql://user:password@host:port/dbname
    // Default: reads VORTOS_WRITE_DB_DSN from ENV.
    //
    // $config->writeDsn($_ENV['VORTOS_WRITE_DB_DSN'] ?? '');

    // Read database DSN — MongoDB (or same as write for DBAL read replicas).
    // Format: mongodb://user:password@host:port
    // Default: reads VORTOS_READ_DB_DSN from ENV.
    //
    // $config->readDsn($_ENV['VORTOS_READ_DB_DSN'] ?? '');

    // Read database name — used by MongoDB read repositories.
    // Default: reads VORTOS_READ_DB_NAME from ENV.
    //
    // $config->readDatabase($_ENV['VORTOS_READ_DB_NAME'] ?? '');
};
