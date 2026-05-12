<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class ObservabilityPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ObservabilityExtension();
    }

    public function build(ContainerBuilder $container): void {}
}

