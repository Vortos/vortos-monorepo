<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Configuration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Injects PersistenceMetricsDecorator into the DBAL middleware stack.
 *
 * Modifies the existing Configuration::setMiddlewares() call added by
 * DbalPersistenceExtension to append PersistenceMetricsDecorator alongside
 * TracingDbalMiddleware — both decorators wrap the same DBAL driver.
 *
 * Only runs when:
 *   - MetricsInterface is registered (metrics module is active)
 *   - Doctrine\DBAL\Configuration is registered (DbalPersistencePackage is active)
 */
final class PersistenceMetricsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FrameworkTelemetry::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('persistence', $disabled, true)) {
            return;
        }

        if (!$container->hasDefinition(Configuration::class)) {
            return;
        }

        // Register the metrics middleware
        $container->register(PersistenceMetricsDecorator::class, PersistenceMetricsDecorator::class)
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class))
            ->setShared(true)
            ->setPublic(false);

        // Append to the existing setMiddlewares() call on the Configuration definition
        $configDef = $container->getDefinition(Configuration::class);
        $calls     = $configDef->getMethodCalls();

        foreach ($calls as $i => [$method, $args]) {
            if ($method === 'setMiddlewares' && isset($args[0]) && is_array($args[0])) {
                $args[0][]   = new Reference(PersistenceMetricsDecorator::class);
                $calls[$i]   = [$method, $args];
                $configDef->setMethodCalls($calls);
                return;
            }
        }

        // setMiddlewares was not yet called — add it fresh (DbalPersistencePackage absent)
        $configDef->addMethodCall('setMiddlewares', [[new Reference(PersistenceMetricsDecorator::class)]]);
    }
}
