<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\FeatureFlagsAdmin\DependencyInjection\Compiler\TwigExtensionCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class FeatureFlagsAdminPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FeatureFlagsAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigExtensionCompilerPass());
    }
}
