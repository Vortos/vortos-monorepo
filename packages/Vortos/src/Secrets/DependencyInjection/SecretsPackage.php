<?php

declare(strict_types=1);

namespace Vortos\Secrets\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;
use Vortos\Secrets\DependencyInjection\Compiler\CollectKeyProvidersPass;
use Vortos\Secrets\DependencyInjection\Compiler\CollectSecretsProvidersPass;

final class SecretsPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new SecretsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectSecretsProvidersPass());
        CollectDriversCompilerPass::register($container, new CollectKeyProvidersPass());
    }
}
