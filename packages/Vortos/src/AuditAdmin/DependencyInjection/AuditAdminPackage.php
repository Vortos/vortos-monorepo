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
        // Priority 82 sits in the window: AFTER vortos-audit's AuditExportObjectStorePass (95,
        // which registers AuditExportService) so the service exists, and BEFORE http's
        // RouteCompilerPass (80) so the export controllers — tagged 'vortos.api.controller' —
        // are seen by route discovery. (A late pass would register them after routes are built.)
        $container->addCompilerPass(
            new AuditExportControllerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            82,
        );
    }
}
