<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Health\DependencyInjection\Compiler\BridgeLegacyHealthChecksPass;
use Vortos\Health\DependencyInjection\Compiler\CollectHealthProbesPass;
use Vortos\Health\DependencyInjection\Compiler\CollectUptimeMonitorsPass;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class HealthPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new HealthExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new BridgeLegacyHealthChecksPass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -30,
        );

        // The uptime sync record must be durable whenever a database exists. The extension's own
        // has(Connection::class) is a race against extension load order and lost it in production,
        // leaving health:monitor:sync unable to remember what it last pushed.
        $container->addCompilerPass(
            new \Vortos\Health\DependencyInjection\Compiler\DurableSyncRecordStorePass(),
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -20,
        );

        CollectDriversCompilerPass::register($container, new CollectHealthProbesPass());
        CollectDriversCompilerPass::register($container, new CollectUptimeMonitorsPass());
    }
}
