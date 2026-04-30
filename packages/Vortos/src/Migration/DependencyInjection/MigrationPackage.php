<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class MigrationPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MigrationExtension();
    }

    public function build(ContainerBuilder $container): void {}
}
