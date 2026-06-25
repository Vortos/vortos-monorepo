<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class DeployK8sPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new DeployK8sExtension();
    }

    public function build(ContainerBuilder $container): void
    {
    }
}
