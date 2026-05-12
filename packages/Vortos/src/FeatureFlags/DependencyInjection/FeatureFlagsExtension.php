<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\FeatureFlags\Command\FlagsAddRuleCommand;
use Vortos\FeatureFlags\Command\FlagsCreateCommand;
use Vortos\FeatureFlags\Command\FlagsDeleteCommand;
use Vortos\FeatureFlags\Command\FlagsDisableCommand;
use Vortos\FeatureFlags\Command\FlagsEnableCommand;
use Vortos\FeatureFlags\Command\FlagsListCommand;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Http\DefaultFlagContextResolver;
use Vortos\FeatureFlags\Http\FeatureFlagMiddleware;
use Vortos\FeatureFlags\Http\FlagContextResolverInterface;
use Vortos\FeatureFlags\Http\FlagsController;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Storage\RedisCachingStorage;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Tracing\Contract\TracingInterface;

final class FeatureFlagsExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_feature_flags';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(DatabaseFlagStorage::class, DatabaseFlagStorage::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setPublic(false);

        // Wrap with Redis cache if PSR-16 cache is available
        $cacheRef = $container->has(CacheInterface::class)
            ? new Reference(CacheInterface::class)
            : null;

        $container->register(RedisCachingStorage::class, RedisCachingStorage::class)
            ->setArguments([
                new Reference(DatabaseFlagStorage::class),
                $cacheRef,
                60,
            ])
            ->setPublic(false);

        $container->setAlias(FlagStorageInterface::class, RedisCachingStorage::class)
            ->setPublic(false);

        $container->register(FlagEvaluator::class, FlagEvaluator::class)
            ->setPublic(false);

        $container->register(FlagRegistry::class, FlagRegistry::class)
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$evaluator', new Reference(FlagEvaluator::class))
            ->setShared(true)
            ->setPublic(true);

        $container->setAlias(FlagRegistryInterface::class, FlagRegistry::class)
            ->setPublic(true);

        $container->register(DefaultFlagContextResolver::class, DefaultFlagContextResolver::class)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setPublic(false);

        $container->setAlias(FlagContextResolverInterface::class, DefaultFlagContextResolver::class)
            ->setPublic(true);

        $container->register(FeatureFlagMiddleware::class, FeatureFlagMiddleware::class)
            ->setArgument('$registry', new Reference(FlagRegistry::class))
            ->setArgument('$contextResolver', new Reference(FlagContextResolverInterface::class))
            ->setArgument('$flagMap', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$tracer', new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);

        $container->register(FlagsController::class, FlagsController::class)
            ->setArgument('$registry', new Reference(FlagRegistry::class))
            ->setArgument('$contextResolver', new Reference(FlagContextResolverInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        foreach ([
            FlagsListCommand::class,
            FlagsCreateCommand::class,
            FlagsEnableCommand::class,
            FlagsDisableCommand::class,
            FlagsDeleteCommand::class,
            FlagsAddRuleCommand::class,
        ] as $command) {
            $container->register($command, $command)
                ->setArgument('$storage', new Reference(FlagStorageInterface::class))
                ->addTag('console.command')
                ->setPublic(false);
        }
    }
}
