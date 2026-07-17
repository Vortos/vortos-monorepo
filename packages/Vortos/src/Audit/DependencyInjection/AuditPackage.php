<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Audit\DependencyInjection\Compiler\AuditActionProviderPass;
use Vortos\Audit\DependencyInjection\Compiler\AuditExportObjectStorePass;
use Vortos\Audit\DependencyInjection\Compiler\AuditRetentionArchivePass;
use Vortos\Foundation\Contract\PackageInterface;

final class AuditPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuditExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs late so it sees action providers registered by every other module + the app.
        $container->addCompilerPass(
            new AuditActionProviderPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -50,
        );

        // Wires the retention cold-archive target after every extension's load() — so the
        // object-store alias check is load-order-independent.
        $container->addCompilerPass(
            new AuditRetentionArchivePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -60,
        );

        // Wires the async-export object-store target (sink + streaming exporter + service +
        // consumer handler + GC). Priority 95 places it in the window AFTER messaging's
        // MessagingConfigCompilerPass (100, registers consumers) but BEFORE HandlerDiscovery
        // (90) — the export consumer HANDLER carries the 'vortos.event_handler' tag, so it must
        // exist before discovery runs, and the object-store alias it needs is already present
        // (all extension load() runs before any pass).
        $container->addCompilerPass(
            new AuditExportObjectStorePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            95,
        );
    }
}
