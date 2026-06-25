<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContainerRegistriesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContractReadinessPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCredentialProvidersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectRegistryAuthStrategiesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployAuditSinksPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStateStoresPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStrategiesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCanaryAnalyzersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectEdgeRoutersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectWorkerControllersPass;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class DeployPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new DeployExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectDeployTargetsPass());
        CollectDriversCompilerPass::register($container, new CollectContainerRegistriesPass());
        CollectDriversCompilerPass::register($container, new CollectCredentialProvidersPass());
        CollectDriversCompilerPass::register($container, new CollectRegistryAuthStrategiesPass());
        CollectDriversCompilerPass::register($container, new CollectDeployStateStoresPass());
        CollectDriversCompilerPass::register($container, new CollectContractReadinessPass());
        CollectDriversCompilerPass::register($container, new CollectEdgeRoutersPass());
        CollectDriversCompilerPass::register($container, new CollectWorkerControllersPass());
        CollectDriversCompilerPass::register($container, new CollectCanaryAnalyzersPass());

        $container->addCompilerPass(
            new CollectDeployStrategiesPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -32,
        );

        $container->addCompilerPass(
            new CollectDeployAuditSinksPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -32,
        );
    }
}
