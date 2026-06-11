<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Iac\DependencyInjection\Compiler\InfraConfigCompilerPass;

final class IacPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new IacExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new InfraConfigCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            20,  // after MessagingConfigCompilerPass (100) — reads vortos.transports while env placeholders are still raw
        );
    }
}
