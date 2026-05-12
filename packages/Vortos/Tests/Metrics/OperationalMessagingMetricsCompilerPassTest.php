<?php

declare(strict_types=1);

namespace Vortos\Tests\Metrics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Metrics\Adapter\NoOpMetrics;
use Vortos\Metrics\AutoInstrumentation\OperationalMessagingMetricsCollector;
use Vortos\Metrics\AutoInstrumentation\OperationalMessagingMetricsCompilerPass;
use Vortos\Metrics\Contract\MetricsInterface;

final class OperationalMessagingMetricsCompilerPassTest extends TestCase
{
    public function test_registers_operational_messaging_collector_when_metrics_and_dbal_exist(): void
    {
        $container = new ContainerBuilder();
        $container->register(NoOpMetrics::class, NoOpMetrics::class);
        $container->setAlias(MetricsInterface::class, NoOpMetrics::class);
        $container->register(Connection::class, Connection::class);
        $container->setParameter('vortos.metrics.disabled_modules', []);
        $container->setParameter('vortos.messaging.outbox_table', 'custom_outbox');
        $container->setParameter('vortos.messaging.dlq_table', 'custom_failed_messages');

        (new OperationalMessagingMetricsCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(OperationalMessagingMetricsCollector::class));
        $definition = $container->getDefinition(OperationalMessagingMetricsCollector::class);
        $this->assertArrayHasKey('vortos.metrics_collector', $definition->getTags());
        $this->assertSame('custom_outbox', $definition->getArgument(2));
        $this->assertSame('custom_failed_messages', $definition->getArgument(3));
    }

    public function test_skips_collector_when_messaging_metrics_are_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->register(NoOpMetrics::class, NoOpMetrics::class);
        $container->setAlias(MetricsInterface::class, NoOpMetrics::class);
        $container->register(Connection::class, Connection::class);
        $container->setParameter('vortos.metrics.disabled_modules', ['messaging']);

        (new OperationalMessagingMetricsCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(OperationalMessagingMetricsCollector::class));
    }
}
