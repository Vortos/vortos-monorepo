<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\MongoFlagAuditLogRepository;
use Vortos\FeatureFlags\ReadModel\MongoFlagStateViewRepository;

/**
 * OPTIONAL override: if a MongoDB client is configured, store the Block 7 read models in
 * Mongo instead of the default relational (DBAL) tables. Mongo is never required — without
 * it, the read models live in the same relational DB as everything else.
 *
 * Runs at priority 10 (before {@see \Vortos\PersistenceMongo\DependencyInjection\Compiler\MongoReadRepositoryAutowirePass}
 * at 8) so the repos it registers get their `$store` auto-wired. The projector already
 * depends on the repository interfaces, so repointing the aliases is all that's needed.
 */
final class FlagReadModelCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(\MongoDB\Client::class)) {
            return; // no Mongo → keep the default DBAL read models
        }

        // The MongoReadRepositoryAutowirePass fills '$store' for #[MongoCollection] classes.
        $container->setDefinition(
            MongoFlagAuditLogRepository::class,
            (new Definition(MongoFlagAuditLogRepository::class))->setPublic(false),
        );
        $container->setDefinition(
            MongoFlagStateViewRepository::class,
            (new Definition(MongoFlagStateViewRepository::class))->setPublic(false),
        );

        $container->setAlias(FlagAuditLogRepositoryInterface::class, MongoFlagAuditLogRepository::class)
            ->setPublic(false);
        $container->setAlias(FlagStateViewRepositoryInterface::class, MongoFlagStateViewRepository::class)
            ->setPublic(false);
    }
}
