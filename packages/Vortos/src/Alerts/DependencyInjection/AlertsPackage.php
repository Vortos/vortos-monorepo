<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Alerts\DependencyInjection\Compiler\AlertsExternalDefaultsPass;
use Vortos\Alerts\DependencyInjection\Compiler\CollectNotifiersPass;
use Vortos\Alerts\DependencyInjection\Compiler\AlertAuditWiringPass;
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
        // Cross-package: the tamper-evident audit ledger needs a DBAL Connection and the hash
        // chain. Asking for either in the extension is a race against load order — it lost that
        // race in production and the ledger was never registered at all, recording nothing while
        // alerts delivered normally. See AlertAuditWiringPass.
        //
        // The priority MUST stay below AlertsExternalDefaultsPass (-16), because that pass
        // registers the AuditHashChain fallback this one requires. Symfony runs passes in
        // DESCENDING priority order, so a larger number runs EARLIER. This was -14 in alpha-285,
        // which put it before the fallback rather than after: the guard found no hash chain, the
        // pass returned early, and the ledger was still never registered. The bug the pass was
        // written to fix survived its own fix, because the ordering was asserted in a docblock
        // and never tested. AlertsPackagePassOrderTest now pins it.
        $container->addCompilerPass(new AlertAuditWiringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -17);
    }
}
