<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Metrics\AutoInstrumentation\MessagingMetricsCompilerPass;
use Vortos\Metrics\AutoInstrumentation\MessagingMetricsDecorator;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class MessagingMetricsCompilerPassTest extends TestCase
{
    public function test_decorates_event_bus_when_present(): void
    {
        $container = $this->makeContainer();

        (new MessagingMetricsCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(MessagingMetricsDecorator::class));
        $this->assertSame(
            EventBusInterface::class,
            $container->getDefinition(MessagingMetricsDecorator::class)->getDecoratedService()[0],
        );
    }

    public function test_does_nothing_when_event_bus_absent(): void
    {
        $container = new ContainerBuilder();
        $container->register(FrameworkTelemetry::class, FrameworkTelemetry::class);
        $container->setParameter('vortos.metrics.disabled_modules', []);

        (new MessagingMetricsCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(MessagingMetricsDecorator::class));
    }

    public function test_does_nothing_when_messaging_module_disabled(): void
    {
        $container = $this->makeContainer();
        $container->setParameter('vortos.metrics.disabled_modules', ['messaging']);

        (new MessagingMetricsCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(MessagingMetricsDecorator::class));
    }

    public function test_does_nothing_when_framework_telemetry_absent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EventBusInterface::class, new Definition(\stdClass::class));
        $container->setParameter('vortos.metrics.disabled_modules', []);

        (new MessagingMetricsCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(MessagingMetricsDecorator::class));
    }

    private function makeContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(FrameworkTelemetry::class, FrameworkTelemetry::class);
        $container->setDefinition(EventBusInterface::class, new Definition(\stdClass::class));
        $container->setParameter('vortos.metrics.disabled_modules', []);
        return $container;
    }
}
