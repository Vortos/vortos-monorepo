<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricDefinitionRegistryFactory;
use Vortos\Metrics\DependencyInjection\MetricDefinitionsCompilerPass;

final class MetricDefinitionsCompilerPassTest extends TestCase
{
    public function test_merges_tagged_provider_definitions_into_registry(): void
    {
        $container = $this->containerWithRegistry([]);

        $container->register(StubMetricDefinitions::class, StubMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        (new MetricDefinitionsCompilerPass())->process($container);

        $definitions = $container->getDefinition(MetricDefinitionRegistry::class)->getArgument('$definitions');

        $names = array_column($definitions, 'name');
        $this->assertContains('stub_counter_total', $names);
    }

    public function test_merges_additional_definitions_onto_existing_user_definitions(): void
    {
        $userDef = MetricDefinition::counter('user_orders_total', 'User-defined counter.', ['channel'])->toArray();
        $container = $this->containerWithRegistry([$userDef]);

        $container->register(StubMetricDefinitions::class, StubMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        (new MetricDefinitionsCompilerPass())->process($container);

        $definitions = $container->getDefinition(MetricDefinitionRegistry::class)->getArgument('$definitions');
        $names = array_column($definitions, 'name');

        $this->assertContains('user_orders_total', $names);
        $this->assertContains('stub_counter_total', $names);
    }

    public function test_noop_when_no_tagged_providers(): void
    {
        $userDef = MetricDefinition::counter('user_orders_total', 'User-defined counter.', ['channel'])->toArray();
        $container = $this->containerWithRegistry([$userDef]);

        (new MetricDefinitionsCompilerPass())->process($container);

        $definitions = $container->getDefinition(MetricDefinitionRegistry::class)->getArgument('$definitions');
        $this->assertCount(1, $definitions);
        $this->assertSame('user_orders_total', $definitions[0]['name']);
    }

    public function test_noop_when_registry_not_registered(): void
    {
        $container = new ContainerBuilder();

        $container->register(StubMetricDefinitions::class, StubMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        // Must not throw.
        (new MetricDefinitionsCompilerPass())->process($container);
        $this->assertTrue(true);
    }

    public function test_multiple_providers_all_merged(): void
    {
        $container = $this->containerWithRegistry([]);

        $container->register(StubMetricDefinitions::class, StubMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        $container->register(AnotherStubMetricDefinitions::class, AnotherStubMetricDefinitions::class)
            ->addTag(MetricDefinitionProviderInterface::TAG)
            ->setPublic(false);

        (new MetricDefinitionsCompilerPass())->process($container);

        $definitions = $container->getDefinition(MetricDefinitionRegistry::class)->getArgument('$definitions');
        $names = array_column($definitions, 'name');

        $this->assertContains('stub_counter_total', $names);
        $this->assertContains('another_gauge_total', $names);
    }

    private function containerWithRegistry(array $initialDefinitions): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(MetricDefinitionRegistry::class, MetricDefinitionRegistry::class)
            ->setFactory([MetricDefinitionRegistryFactory::class, 'create'])
            ->setArgument('$definitions', $initialDefinitions)
            ->setShared(true)
            ->setPublic(false);

        return $container;
    }
}

final class StubMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::counter('stub_counter_total', 'A stub counter for testing.', ['label_a']),
        ];
    }
}

final class AnotherStubMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::gauge('another_gauge_total', 'Another stub gauge for testing.', ['label_b']),
        ];
    }
}
