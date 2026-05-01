<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Factory;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

/**
 * Builds a Doctrine EntityManager from a DSN string and entity paths.
 *
 * Static factory — pure construction, no state.
 *
 * Entity paths tell Doctrine where to scan for #[ORM\Entity] attributes.
 * Pass your application's src/ directory (and any domain package directories
 * that contain ORM entities).
 *
 * ## Dev mode
 *
 * isDevMode: true — Doctrine re-reads entity metadata on every request.
 * Set to false in production via the APP_ENV environment variable or
 * the $devMode argument for performance.
 *
 * In production, Doctrine caches metadata in memory after first load.
 * In dev, it always re-reads — so you see mapping changes immediately.
 */
final class EntityManagerFactory
{
    private function __construct() {}

    public static function fromDsn(string $dsn, array $entityPaths, bool $devMode = false): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: $entityPaths,
            isDevMode: $devMode,
        );

        $parser = new DsnParser([
            'pgsql'    => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'mysql'    => 'pdo_mysql',
            'sqlite'   => 'pdo_sqlite',
            'sqlsrv'   => 'pdo_sqlsrv',
            'oci8'     => 'oci8',
        ]);

        $params     = $parser->parse($dsn);
        $connection = DriverManager::getConnection($params, $config);

        return new EntityManager($connection, $config);
    }
}
