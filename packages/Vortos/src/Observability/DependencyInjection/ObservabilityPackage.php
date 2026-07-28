<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Observability\DependencyInjection\Compiler\CollectErrorSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\DeployAuditWiringPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMarkerEmittersPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsQueriesPass;
use Vortos\Observability\DependencyInjection\Compiler\ResolveMetricsNamespacePass;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class ObservabilityPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ObservabilityExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // The deploy audit ledger needs a DBAL Connection registered by another package. Asking in
        // the extension is a race that silently drops the whole ledger — the same defect that left
        // the ALERT audit ledger empty in production. See DeployAuditWiringPass.
        $container->addCompilerPass(
            new DeployAuditWiringPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -20,
        );

        // vortos-metrics publishes the configured namespace as a parameter, and extension load
        // order does not guarantee it exists yet during ObservabilityExtension::load().
        $container->addCompilerPass(
            new ResolveMetricsNamespacePass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -20,
        );

        CollectDriversCompilerPass::register($container, new CollectMetricsSinksPass());
        CollectDriversCompilerPass::register($container, new CollectErrorSinksPass());
        CollectDriversCompilerPass::register($container, new CollectMarkerEmittersPass());
        CollectDriversCompilerPass::register($container, new CollectMetricsQueriesPass());
    }
}

