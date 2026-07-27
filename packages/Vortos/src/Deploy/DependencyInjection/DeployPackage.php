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
use Vortos\Deploy\DependencyInjection\Compiler\TagPreflightChecksPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStateStoresPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStrategiesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCanaryAnalyzersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectEdgeRoutersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectWorkerControllersPass;
use Vortos\Deploy\DependencyInjection\Compiler\DeployWiringPass;
use Vortos\Deploy\DependencyInjection\Compiler\HttpClientDefaultsPass;
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

        // Tag every PreflightCheckInterface service, whichever package registered it. Runs at a
        // priority low enough that all extensions have registered their checks, and BEFORE the
        // runner's TaggedIteratorArgument is resolved. Without this the runner collected only the
        // one check that happened to be autoconfigured and every other deploy gate was inert.
        $container->addCompilerPass(
            new TagPreflightChecksPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -48,
        );

        // Cross-package deploy wiring (release read model + migration readers). Must run in a
        // pass — not load() — so has() reflects the fully-merged container and is order-free.
        $container->addCompilerPass(
            new DeployWiringPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -64,
        );

        // Bind default PSR-18/PSR-17 HTTP services only if the app hasn't. Low priority so
        // every extension's and the app's own bindings are already present when it runs.
        $container->addCompilerPass(
            new HttpClientDefaultsPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -256,
        );
    }
}
