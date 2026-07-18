<?php

declare(strict_types=1);

namespace Vortos\Search\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Search\Backfill\SearchBackfillSourceInterface;
use Vortos\Search\Backfill\SearchIndexRebuilder;
use Vortos\Search\Cache\NullSearchCache;
use Vortos\Search\Cache\SearchCacheInterface;
use Vortos\Search\Console\SearchPgInstallCommand;
use Vortos\Search\Console\SearchRebuildCommand;
use Vortos\Search\Enum\SearchDriver;
use Vortos\Search\Index\Dbal\DbalSearchIndexWriter;
use Vortos\Search\Index\Dbal\DbalSearchReader;
use Vortos\Search\Index\Dbal\Postgres\PostgresSearchExtrasInstaller;
use Vortos\Search\Index\PortableLikeSearchDriver;
use Vortos\Search\Index\PostgresFtsSearchDriver;
use Vortos\Search\Index\SearchIndexDriver;
use Vortos\Search\Index\SearchIndexWriterInterface;
use Vortos\Search\Observability\NullSearchMetrics;
use Vortos\Search\Observability\SearchMetricsInterface;
use Vortos\Search\Projection\SearchableProjection;
use Vortos\Search\Projection\SearchProjectionApplier;
use Vortos\Search\Projection\SearchProjectorRegistry;
use Vortos\Search\Query\SearchQueryService;
use Vortos\Search\Query\SearchReaderInterface;

/**
 * Wires the search engine.
 *
 * The framework owns matching/ranking (driver), scoping (reader), the projector registry +
 * applier, backfill, cache/metrics seams and the Postgres install/rebuild commands. The app
 * owns only: the tagged {@see SearchableProjection}s + {@see SearchBackfillSourceInterface}s
 * (auto-discovered here), a thin Kafka handler that calls the applier, and the query endpoint.
 */
final class SearchExtension extends Extension
{
    /** Service tags apps' projectors/backfill-sources carry (added automatically via autoconfiguration). */
    public const TAG_PROJECTION = 'vortos.search.projection';
    public const TAG_BACKFILL   = 'vortos.search.backfill_source';

    public function getAlias(): string
    {
        return 'vortos_search';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->loadConfig($container);

        // Any app SearchableProjection / SearchBackfillSource is auto-tagged, so the registry +
        // rebuilder pick it up with no extra wiring — the auto-searchable seam.
        $container->registerForAutoconfiguration(SearchableProjection::class)->addTag(self::TAG_PROJECTION);
        $container->registerForAutoconfiguration(SearchBackfillSourceInterface::class)->addTag(self::TAG_BACKFILL);

        $this->registerDriver($container, $config);
        $this->registerIndex($container, $config);
        $this->registerQuery($container, $config);
        $this->registerProjection($container);
        $this->registerBackfill($container);
    }

    /** @param array<string, mixed> $config */
    private function registerDriver(ContainerBuilder $container, array $config): void
    {
        $usePostgres = $config['driver'] === SearchDriver::PostgresFts->value && $this->isPostgres($container);

        $container->register(PortableLikeSearchDriver::class, PortableLikeSearchDriver::class)->setPublic(false);
        $container->register(PostgresFtsSearchDriver::class, PostgresFtsSearchDriver::class)->setPublic(false);

        $container->setAlias(
            SearchIndexDriver::class,
            $usePostgres ? PostgresFtsSearchDriver::class : PortableLikeSearchDriver::class,
        );
    }

    /** @param array<string, mixed> $config */
    private function registerIndex(ContainerBuilder $container, array $config): void
    {
        // Cache + metrics default to no-ops; the app re-aliases them (Redis / observability).
        if (!$container->hasAlias(SearchCacheInterface::class) && !$container->hasDefinition(SearchCacheInterface::class)) {
            $container->register(NullSearchCache::class, NullSearchCache::class)->setPublic(false);
            $container->setAlias(SearchCacheInterface::class, NullSearchCache::class);
        }
        if (!$container->hasAlias(SearchMetricsInterface::class) && !$container->hasDefinition(SearchMetricsInterface::class)) {
            $container->register(NullSearchMetrics::class, NullSearchMetrics::class)->setPublic(false);
            $container->setAlias(SearchMetricsInterface::class, NullSearchMetrics::class);
        }

        if (!class_exists(Connection::class)) {
            return; // No DBAL: app must supply its own writer + reader (external engine).
        }

        $table = $this->tablePrefix($container) . 'search_documents';

        $container->register(DbalSearchIndexWriter::class, DbalSearchIndexWriter::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$metrics', new Reference(SearchMetricsInterface::class))
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$table', $table)
            ->setPublic(false);
        $container->setAlias(SearchIndexWriterInterface::class, DbalSearchIndexWriter::class);

        $container->register(DbalSearchReader::class, DbalSearchReader::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$driver', new Reference(SearchIndexDriver::class))
            ->setArgument('$table', $table)
            ->setPublic(false);
        $container->setAlias(SearchReaderInterface::class, DbalSearchReader::class);

        // Postgres-only extras installer + command.
        if ($this->isPostgres($container)) {
            $container->register(PostgresSearchExtrasInstaller::class, PostgresSearchExtrasInstaller::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $table)
                ->setPublic(false);

            $container->register(SearchPgInstallCommand::class, SearchPgInstallCommand::class)
                ->setArgument('$installer', new Reference(PostgresSearchExtrasInstaller::class))
                ->setArgument('$rlsConfigured', (bool) $config['row_level_security'])
                ->addTag('console.command')
                ->setPublic(false);
        }
    }

    /** @param array<string, mixed> $config */
    private function registerQuery(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasAlias(SearchReaderInterface::class) && !$container->hasDefinition(SearchReaderInterface::class)) {
            return; // No reader (no DBAL, app hasn't supplied one yet) — nothing to serve.
        }

        $container->register(SearchQueryService::class, SearchQueryService::class)
            ->setArgument('$reader', new Reference(SearchReaderInterface::class))
            ->setArgument('$cache', new Reference(SearchCacheInterface::class))
            ->setArgument('$metrics', new Reference(SearchMetricsInterface::class))
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$cacheTtlSeconds', (int) $config['cache_ttl_seconds'])
            ->setPublic(true);
    }

    private function registerProjection(ContainerBuilder $container): void
    {
        $container->register(SearchProjectorRegistry::class, SearchProjectorRegistry::class)
            ->setArgument('$projectors', new TaggedIteratorArgument(self::TAG_PROJECTION))
            ->setPublic(false);

        if (!$container->hasAlias(SearchIndexWriterInterface::class) && !$container->hasDefinition(SearchIndexWriterInterface::class)) {
            return; // Applier needs a writer; without one the app wires its own.
        }

        $container->register(SearchProjectionApplier::class, SearchProjectionApplier::class)
            ->setArgument('$registry', new Reference(SearchProjectorRegistry::class))
            ->setArgument('$writer', new Reference(SearchIndexWriterInterface::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(true);
    }

    private function registerBackfill(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(SearchIndexWriterInterface::class) && !$container->hasDefinition(SearchIndexWriterInterface::class)) {
            return;
        }

        $container->register(SearchIndexRebuilder::class, SearchIndexRebuilder::class)
            ->setArgument('$writer', new Reference(SearchIndexWriterInterface::class))
            ->setArgument('$sources', new TaggedIteratorArgument(self::TAG_BACKFILL))
            ->setPublic(true);

        $container->register(SearchRebuildCommand::class, SearchRebuildCommand::class)
            ->setArgument('$rebuilder', new Reference(SearchIndexRebuilder::class))
            ->addTag('console.command')
            ->setPublic(false);
    }

    /** @return array<string, mixed> */
    private function loadConfig(ContainerBuilder $container): array
    {
        $config = new VortosSearchConfig();

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDir = (string) $container->getParameter('kernel.project_dir');
            $env        = $container->hasParameter('kernel.env') ? (string) $container->getParameter('kernel.env') : 'prod';

            foreach (["{$projectDir}/config/search.php", "{$projectDir}/config/{$env}/search.php"] as $file) {
                if (is_file($file)) {
                    $loaded = require $file;
                    if ($loaded instanceof \Closure) {
                        $loaded($config);
                    }
                }
            }
        }

        return $config->toArray();
    }

    private function tablePrefix(ContainerBuilder $container): string
    {
        return $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
    }

    private function isPostgres(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter('vortos.persistence.write_dsn')) {
            return true;
        }

        $dsn = strtolower((string) $container->getParameter('vortos.persistence.write_dsn'));

        return $dsn === ''
            || str_starts_with($dsn, 'pgsql')
            || str_starts_with($dsn, 'postgres')
            || str_contains($dsn, 'postgresql');
    }
}
