<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection\Compiler;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\Contract\ObjectStoreRouterInterface;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;
use Vortos\ObjectStore\Metrics\ObjectStoreMetricDefinitions;
use Vortos\ObjectStore\Policy\NoOpObjectPromotionPolicy;
use Vortos\ObjectStore\Router\SingleObjectStoreRouter;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wires ObjectStore dependencies on services owned by other packages and
 * defaults that the app may override.
 *
 * Each of these decisions used to be made inside ObjectStoreExtension::load()
 * with a has()/hasAlias()/hasDefinition() check, which runs against the isolated
 * per-extension container built by MergeExtensionConfigurationPass — so the
 * Metrics registry, Tracing/Metrics services, and any app-provided clock / policy
 * / router override were never visible. As a result the default clock/policy/router
 * could clobber app overrides non-deterministically, ObjectStore metric definitions
 * were never registered, and the S3 lifecycle manager was never given a tracer or
 * metrics. This pass applies all of it against the fully merged container.
 */
final class ObjectStoreRuntimeDependenciesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->provideDefaultClock($container);
        $this->setDefaultAlias($container, ObjectPromotionPolicyInterface::class, NoOpObjectPromotionPolicy::class);
        $this->setDefaultAlias($container, ObjectStoreRouterInterface::class, SingleObjectStoreRouter::class);
        $this->appendMetricDefinitions($container);
        $this->wireLifecycleObservability($container);
    }

    private function provideDefaultClock(ContainerBuilder $container): void
    {
        if ($container->has(ClockInterface::class)) {
            return;
        }

        $container->register(ClockInterface::class, NativeClock::class)
            ->setShared(true)
            ->setPublic(false);
    }

    private function setDefaultAlias(ContainerBuilder $container, string $interface, string $concrete): void
    {
        if (!$container->hasDefinition($concrete)) {
            return;
        }

        if ($container->hasAlias($interface) || $container->hasDefinition($interface)) {
            return;
        }

        $container->setAlias($interface, $concrete)->setPublic(false);
    }

    private function appendMetricDefinitions(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ObjectStoreMetricDefinitions::class)
            || !$container->hasDefinition(MetricDefinitionRegistry::class)) {
            return;
        }

        $registry = $container->getDefinition(MetricDefinitionRegistry::class);
        $definitions = $registry->getArgument('$definitions');
        foreach ((new ObjectStoreMetricDefinitions())->definitions() as $metricDefinition) {
            $definitions[] = $metricDefinition->toArray();
        }
        $registry->setArgument('$definitions', $definitions);
    }

    private function wireLifecycleObservability(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(S3LifecycleManager::class)) {
            return;
        }

        $manager = $container->getDefinition(S3LifecycleManager::class);

        if ($container->getParameter('vortos_object_store.lifecycle.wants_tracer')
            && $container->has(TracingInterface::class)) {
            $manager->setArgument('$tracer', new Reference(TracingInterface::class));
        }

        if ($container->getParameter('vortos_object_store.lifecycle.wants_metrics')
            && $container->has(MetricsInterface::class)) {
            $manager->setArgument('$metrics', new Reference(MetricsInterface::class));
        }
    }
}
