<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class PersistenceOrmPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PersistenceOrmExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // No compiler passes needed.
    }
}
