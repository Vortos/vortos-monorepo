<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

final class OperationalMessagingMetricsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FrameworkTelemetry::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('messaging', $disabled, true)) {
            return;
        }

        if (!$container->hasDefinition(Connection::class) && !$container->hasAlias(Connection::class)) {
            return;
        }

        $outboxTable = $container->hasParameter('vortos.messaging.outbox_table')
            ? (string) $container->getParameter('vortos.messaging.outbox_table')
            : 'vortos_outbox';
        $deadLetterTable = $container->hasParameter('vortos.messaging.dlq_table')
            ? (string) $container->getParameter('vortos.messaging.dlq_table')
            : 'vortos_failed_messages';

        $container->register(OperationalMessagingMetricsCollector::class, OperationalMessagingMetricsCollector::class)
            ->setArguments([
                new Reference(Connection::class),
                new Reference(FrameworkTelemetry::class),
                $outboxTable,
                $deadLetterTable,
            ])
            ->addTag('vortos.metrics_collector')
            ->setPublic(false);
    }
}
