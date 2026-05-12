<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Decorates CommandBusInterface with CqrsMetricsDecorator.
 *
 * Runs as a compiler pass because CqrsPackage (order 90) loads AFTER MetricsPackage (order 55),
 * so CommandBusInterface is not yet registered when MetricsExtension::load() runs.
 * Compiler passes run after all extensions have loaded — CommandBusInterface is guaranteed
 * to be defined by then.
 *
 * Skipped when MetricsModule::Cqrs is in the disabled modules list.
 */
final class CqrsMetricsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FrameworkTelemetry::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('cqrs', $disabled, true)) {
            return;
        }

        if (!$container->hasAlias(CommandBusInterface::class) && !$container->hasDefinition(CommandBusInterface::class)) {
            return;
        }

        $container->register(CqrsMetricsDecorator::class, CqrsMetricsDecorator::class)
            ->setDecoratedService(CommandBusInterface::class)
            ->setArguments([
                new Reference(CqrsMetricsDecorator::class . '.inner'),
                new Reference(FrameworkTelemetry::class),
            ])
            ->setShared(true)
            ->setPublic(false);
    }
}
