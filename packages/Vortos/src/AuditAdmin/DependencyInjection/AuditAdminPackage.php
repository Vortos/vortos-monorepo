<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\AuditAdmin\DependencyInjection\Compiler\AuditExportControllerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class AuditAdminPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuditAdminExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs after vortos-audit's AuditExportObjectStorePass (priority -60): a lower priority
        // (-70) guarantees AuditExportService is registered before we wire its controllers.
        $container->addCompilerPass(
            new AuditExportControllerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -70,
        );
    }
}
