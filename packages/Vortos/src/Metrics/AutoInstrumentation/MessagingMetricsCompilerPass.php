<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Decorates EventBusInterface with MessagingMetricsDecorator.
 *
 * Runs as a compiler pass (mirroring {@see CqrsMetricsCompilerPass}) because a
 * hasAlias/hasDefinition(EventBusInterface) check inside MetricsExtension::load()
 * runs against the isolated per-extension merge container, where EventBusInterface
 * (registered by MessagingExtension::load) is never visible regardless of package
 * load order. Compiler passes run after all extensions have merged, so the bus
 * alias is reliably present.
 *
 * Skipped when ObservabilityModule::Messaging is in the disabled modules list.
 */
final class MessagingMetricsCompilerPass implements CompilerPassInterface
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

        if (!$container->hasAlias(EventBusInterface::class) && !$container->hasDefinition(EventBusInterface::class)) {
            return;
        }

        $container->register(MessagingMetricsDecorator::class, MessagingMetricsDecorator::class)
            ->setDecoratedService(EventBusInterface::class)
            ->setArguments([
                new Reference(MessagingMetricsDecorator::class . '.inner'),
                new Reference(FrameworkTelemetry::class),
            ])
            ->setShared(true)
            ->setPublic(false);
    }
}
