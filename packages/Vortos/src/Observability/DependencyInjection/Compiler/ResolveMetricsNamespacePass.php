<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Observability\Command\GenerateDashboardCommand;
use Vortos\Observability\Telemetry\MetricNamespace;

/**
 * Hands the configured metrics namespace to the dashboard generator.
 *
 * This runs as a compiler pass rather than inside ObservabilityExtension::load() for two reasons.
 * Extensions load in an undefined order, so 'vortos.metrics.namespace' may not exist yet when
 * observability loads; and vortos-metrics is an optional dependency, so it may never exist at all.
 * By the time passes run, every extension has loaded and the parameter is either present or
 * genuinely absent.
 *
 * The direction of the dependency matters: vortos-metrics already depends on vortos-observability
 * for {@see \Vortos\Observability\Telemetry\FrameworkMetric}, so observability cannot depend back on
 * it. A published container parameter is how the namespace crosses that boundary without a cycle.
 */
final class ResolveMetricsNamespacePass implements CompilerPassInterface
{
    public const PARAMETER = 'vortos.metrics.namespace';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(GenerateDashboardCommand::class)) {
            return;
        }

        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }

        $namespace = $container->getParameter(self::PARAMETER);

        if (!is_string($namespace) || $namespace === '') {
            return;
        }

        // Validate here, at build time, rather than letting an unusable namespace reach the
        // generator and produce a dashboard nobody can query.
        MetricNamespace::of($namespace);

        $container->getDefinition(GenerateDashboardCommand::class)
            ->setArgument('$defaultNamespace', $namespace);
    }
}
