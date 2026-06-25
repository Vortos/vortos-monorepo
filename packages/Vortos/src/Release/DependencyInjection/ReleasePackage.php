<?php

declare(strict_types=1);

namespace Vortos\Release\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class ReleasePackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new ReleaseExtension();
    }

    public function build(ContainerBuilder $container): void
    {
    }
}
