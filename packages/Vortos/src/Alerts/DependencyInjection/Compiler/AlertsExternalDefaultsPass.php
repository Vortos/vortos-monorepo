<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Compiler\ConditionalWiringPass;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Observability\Slo\SloRegistry;

/**
 * Registers fallbacks for vortos-observability services the alerts package consumes, but only
 * when observability has not already registered them.
 *
 *  - {@see SloRegistry}: an empty registry so SloBurnAlertSource still wires.
 *  - {@see AuditHashChain}: a default hash chain for the alert audit recorder.
 *
 * "Is the observability-owned service already present?" is a cross-package question, so it runs
 * in a compiler pass (has() is reliable and order-independent) rather than in
 * AlertsExtension::load(), where the answer depends on extension load order.
 */
final class AlertsExternalDefaultsPass extends ConditionalWiringPass
{
    protected function wire(ContainerBuilder $container): void
    {
        if (!$this->optionalCapability($container, SloRegistry::class)) {
            $container->register(SloRegistry::class, SloRegistry::class)
                ->setArgument('$slos', [])
                ->setPublic(true); // app config overrides with declared SLOs
        }

        if (!$this->optionalCapability($container, AuditHashChain::class)) {
            $container->register(AuditHashChain::class, AuditHashChain::class)
                ->setPublic(false);
        }
    }
}
