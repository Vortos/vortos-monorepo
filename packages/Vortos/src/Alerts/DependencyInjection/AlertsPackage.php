<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Alerts\DependencyInjection\Compiler\CollectNotifiersPass;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class AlertsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AlertsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectNotifiersPass());
    }
}
