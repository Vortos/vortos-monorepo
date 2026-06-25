<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupEventSinksPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupStoresPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupTargetsPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectInvariantChecksPass;
use Vortos\Backup\DependencyInjection\Compiler\CollectRestoreTargetsPass;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class BackupPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new BackupExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectBackupTargetsPass());
        CollectDriversCompilerPass::register($container, new CollectBackupStoresPass());
        CollectDriversCompilerPass::register($container, new CollectRestoreTargetsPass());
        $container->addCompilerPass(new CollectBackupEventSinksPass());
        $container->addCompilerPass(new CollectInvariantChecksPass());
    }
}
