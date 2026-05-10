<?php

declare(strict_types=1);

use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

// The persistence DSNs default to ENV vars written by vortos:setup:
//   VORTOS_WRITE_DB_DSN   — write database connection string
//   VORTOS_READ_DB_DSN    — read database connection string
//   VORTOS_READ_DB_NAME   — read database name (MongoDB only)
//
// You only need to override here when you want hard-coded values that
// take precedence over the environment, or when your DSN format differs
// from what vortos:setup writes.
//
// For per-environment overrides create config/{env}/persistence.php.

return static function (VortosPersistenceConfig $config): void {
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
