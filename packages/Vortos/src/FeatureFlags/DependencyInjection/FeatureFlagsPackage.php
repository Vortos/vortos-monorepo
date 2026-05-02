<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

final class FeatureFlagsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FeatureFlagsExtension();
    }

    public function build(ContainerBuilder $container): void {}
}
