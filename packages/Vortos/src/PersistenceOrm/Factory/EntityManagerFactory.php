<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Factory;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds a Doctrine EntityManager from a DSN string and entity paths.
 *
 * Static factory — pure construction, no state.
 *
 * ## Entity paths
 *
 * Paths tell Doctrine where to scan for #[ORM\Entity] attribute at boot time.
 * Pass your application's src/ directory. Scanning only happens once in prod
 * when the metadata cache is warm — zero overhead on subsequent requests.
 *
 * ## Dev mode
 *
 * isDevMode: true  — Doctrine re-reads entity metadata on every request
 *                    (no cache used). Mapping changes are visible immediately.
 * isDevMode: false — Doctrine uses $metadataCache if provided, or falls back
 *                    to an in-process array cache. Always use the PSR-6 cache
 *                    in production for cross-request persistence.
 *
 * ## Metadata cache
 *
 * In production, pass an OrmMetadataCache instance (PSR-6 pool backed by the
 * Vortos TaggedCacheInterface). Doctrine reads entity mappings once on first
 * boot, stores them in Redis via the cache, and serves from Redis on all
 * subsequent requests — zero reflection or file I/O per request.
 *
 * The cache is ignored when isDevMode is true.
 */
final class EntityManagerFactory
{
    private function __construct() {}

    public static function fromDsn(
        string $dsn,
        array $entityPaths,
        bool $devMode = false,
        ?CacheItemPoolInterface $metadataCache = null,
        array $middlewares = [],
    ): EntityManager {
        if (trim($dsn) === '') {
            throw new \RuntimeException('The ORM persistence adapter requires VORTOS_WRITE_DB_DSN to be set.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: $entityPaths,
            isDevMode: $devMode,
            cache: $metadataCache,
        );

        if ($middlewares !== []) {
            $config->setMiddlewares($middlewares);
        }

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
