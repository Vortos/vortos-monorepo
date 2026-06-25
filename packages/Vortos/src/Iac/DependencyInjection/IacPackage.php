<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Iac\DependencyInjection\Compiler\CollectIacEnginesPass;
use Vortos\Iac\DependencyInjection\Compiler\CollectIacPoliciesPass;
use Vortos\Iac\DependencyInjection\Compiler\InfraConfigCompilerPass;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

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
            20,
        );

        CollectDriversCompilerPass::register($container, new CollectIacEnginesPass());
        CollectDriversCompilerPass::register($container, new CollectIacPoliciesPass());
    }
}
