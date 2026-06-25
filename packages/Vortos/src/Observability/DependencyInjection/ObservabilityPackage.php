<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Observability\DependencyInjection\Compiler\CollectErrorSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMarkerEmittersPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsSinksPass;
use Vortos\Observability\DependencyInjection\Compiler\CollectMetricsQueriesPass;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class ObservabilityPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ObservabilityExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectMetricsSinksPass());
        CollectDriversCompilerPass::register($container, new CollectErrorSinksPass());
        CollectDriversCompilerPass::register($container, new CollectMarkerEmittersPass());
        CollectDriversCompilerPass::register($container, new CollectMetricsQueriesPass());
    }
}

