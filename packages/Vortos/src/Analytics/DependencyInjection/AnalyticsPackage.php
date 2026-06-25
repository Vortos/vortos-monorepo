<?php

declare(strict_types=1);

namespace Vortos\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Analytics\DependencyInjection\Compiler\CollectAnalyticsDriversPass;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class AnalyticsPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new AnalyticsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectAnalyticsDriversPass());
    }
}
