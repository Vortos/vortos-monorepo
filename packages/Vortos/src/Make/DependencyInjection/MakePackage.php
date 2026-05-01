<?php

declare(strict_types=1);

namespace Vortos\Make\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class MakePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new MakeExtension();
    }

    public function build(ContainerBuilder $container): void
    {
    }
}
