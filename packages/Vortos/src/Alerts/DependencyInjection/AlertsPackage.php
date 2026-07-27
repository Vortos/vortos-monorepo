<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Alerts\DependencyInjection\Compiler\AlertsExternalDefaultsPass;
use Vortos\Alerts\DependencyInjection\Compiler\CollectNotifiersPass;
use Vortos\Alerts\DependencyInjection\Compiler\DurableAlertStorePass;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

final class AlertsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AlertsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        CollectDriversCompilerPass::register($container, new CollectNotifiersPass());
        // Cross-package: register observability-owned fallbacks (SloRegistry, AuditHashChain)
        // only if observability didn't.
        $container->addCompilerPass(new AlertsExternalDefaultsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        // Cross-package: upgrade the alert stores from the in-memory fallback to DBAL when a
        // Connection exists. MUST be a pass — the extension's own check for it is a race against
        // extension load order, and losing it silently costs the audit trail, cross-process dedupe,
        // ack persistence and maintenance silences. See DurableAlertStorePass.
        $container->addCompilerPass(new DurableAlertStorePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -15);
    }
}
