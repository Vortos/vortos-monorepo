<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\ObjectStore\Contract\ObjectPromotionPolicyInterface;
use Vortos\ObjectStore\DependencyInjection\Compiler\ObjectStoreRuntimeDependenciesPass;
use Vortos\ObjectStore\Lifecycle\S3LifecycleManager;
use Vortos\ObjectStore\Policy\NoOpObjectPromotionPolicy;

final class ObjectStoreRuntimeDependenciesPassTest extends TestCase
{
    public function test_registers_default_clock_when_absent(): void
    {
        $container = new ContainerBuilder();
        $container->register(NoOpObjectPromotionPolicy::class, NoOpObjectPromotionPolicy::class);

        (new ObjectStoreRuntimeDependenciesPass())->process($container);

        $this->assertTrue($container->hasDefinition(ClockInterface::class));
        $this->assertSame(NativeClock::class, $container->getDefinition(ClockInterface::class)->getClass());
    }

    public function test_yields_clock_to_existing_definition(): void
    {
        $container = new ContainerBuilder();
        $container->register(ClockInterface::class, \stdClass::class);

        (new ObjectStoreRuntimeDependenciesPass())->process($container);

        $this->assertSame(\stdClass::class, $container->getDefinition(ClockInterface::class)->getClass());
    }

    public function test_sets_default_promotion_alias_but_yields_to_override(): void
    {
        $container = new ContainerBuilder();
        $container->register(NoOpObjectPromotionPolicy::class, NoOpObjectPromotionPolicy::class);

        (new ObjectStoreRuntimeDependenciesPass())->process($container);
        $this->assertSame(NoOpObjectPromotionPolicy::class, (string) $container->getAlias(ObjectPromotionPolicyInterface::class));

        $overridden = new ContainerBuilder();
        $overridden->register(NoOpObjectPromotionPolicy::class, NoOpObjectPromotionPolicy::class);
        $overridden->register(ObjectPromotionPolicyInterface::class, \stdClass::class);
        (new ObjectStoreRuntimeDependenciesPass())->process($overridden);
        $this->assertFalse($overridden->hasAlias(ObjectPromotionPolicyInterface::class));
    }

    public function test_injects_lifecycle_tracer_and_metrics_when_wanted_and_present(): void
    {
        $container = new ContainerBuilder();
        $container->register(S3LifecycleManager::class, S3LifecycleManager::class);
        $container->setParameter('vortos_object_store.lifecycle.wants_tracer', true);
        $container->setParameter('vortos_object_store.lifecycle.wants_metrics', true);
        $container->setDefinition(\Vortos\Tracing\Contract\TracingInterface::class, new Definition(\stdClass::class));
        $container->setDefinition(\Vortos\Metrics\Contract\MetricsInterface::class, new Definition(\stdClass::class));

        (new ObjectStoreRuntimeDependenciesPass())->process($container);

        $manager = $container->getDefinition(S3LifecycleManager::class);
        $this->assertSame(\Vortos\Tracing\Contract\TracingInterface::class, (string) $manager->getArgument('$tracer'));
        $this->assertSame(\Vortos\Metrics\Contract\MetricsInterface::class, (string) $manager->getArgument('$metrics'));
    }

    public function test_skips_lifecycle_observability_when_not_wanted(): void
    {
        $container = new ContainerBuilder();
        $container->register(S3LifecycleManager::class, S3LifecycleManager::class);
        $container->setParameter('vortos_object_store.lifecycle.wants_tracer', false);
        $container->setParameter('vortos_object_store.lifecycle.wants_metrics', false);
        $container->setDefinition(\Vortos\Tracing\Contract\TracingInterface::class, new Definition(\stdClass::class));

        (new ObjectStoreRuntimeDependenciesPass())->process($container);

        $this->assertArrayNotHasKey('$tracer', $container->getDefinition(S3LifecycleManager::class)->getArguments());
    }
}
