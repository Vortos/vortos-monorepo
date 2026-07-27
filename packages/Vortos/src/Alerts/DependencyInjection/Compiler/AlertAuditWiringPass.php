<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorder;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Alerts\Integration\Audit\AlertAuditViewRepositoryInterface;
use Vortos\Alerts\Integration\Audit\DbalAlertAuditViewRepository;
use Vortos\Alerts\Preflight\AlertAuditLedgerCheck;
use Vortos\Observability\Audit\AuditHashChain;

/**
 * Registers the tamper-evident alert audit ledger, in a pass rather than in Extension::load().
 *
 * WHY A PASS
 *
 * The registration was guarded by `if (!$container->has(Connection::class)) return;` inside
 * AlertsExtension::load(). That is a cross-package availability check asked at the wrong moment:
 * extensions load in an arbitrary order, so whether the DBAL connection is registered *yet* is a
 * race, not a fact. In production the check lost the race, the whole block returned early, and the
 * ledger was never registered at all — no repository, no recorder, no deploy gate. Alerts delivered
 * to Slack normally while the audit trail held zero rows and nothing reported it.
 *
 * A compiler pass runs after every extension has loaded, so `has()` is an answer instead of a
 * guess. This is the same correction DurableAlertStorePass documents for the alert stores; the
 * audit block had the identical bug and was simply never migrated with it.
 *
 * Runs after AlertsExternalDefaultsPass (-16), which registers the AuditHashChain fallback this
 * depends on when vortos-observability is absent.
 */
final class AlertAuditWiringPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(Connection::class) || !$container->has(AuditHashChain::class)) {
            return;
        }

        if ($container->hasDefinition(AlertAuditRecorder::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalAlertAuditViewRepository::class, DbalAlertAuditViewRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'alerts_audit_log')
            ->setPublic(false);
        $container->setAlias(AlertAuditViewRepositoryInterface::class, DbalAlertAuditViewRepository::class)
            ->setPublic(false);

        // The signing key is referenced as an env placeholder, never read here.
        //
        // This used to be `if ($_ENV['ALERTS_AUDIT_HMAC_KEY'] !== '')`, the inline compile-time env
        // read that Foundation\Config\Env exists to forbid: "env access is a declared reference,
        // never an inline read". The container compiles in a clean environment, so the key read as
        // empty and the recorder was omitted even on hosts where the key was set. Whether the key
        // is usable is a RUNTIME question — answered by the recorder and surfaced by the deploy
        // gate below, rather than by a service silently ceasing to exist.
        $container->setParameter('env(ALERTS_AUDIT_HMAC_KEY)', '');

        $container->register(AlertAuditRecorder::class, AlertAuditRecorder::class)
            ->setArgument('$repository', new Reference(AlertAuditViewRepositoryInterface::class))
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setArgument('$hmacKey', '%env(ALERTS_AUDIT_HMAC_KEY)%')
            ->setPublic(true);
        $container->setAlias(AlertAuditRecorderInterface::class, AlertAuditRecorder::class)
            ->setPublic(false);

        if (interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            $container->register(AlertAuditLedgerCheck::class, AlertAuditLedgerCheck::class)
                ->setArgument('$recorder', new Reference(
                    AlertAuditRecorderInterface::class,
                    ContainerInterface::NULL_ON_INVALID_REFERENCE,
                ))
                ->setPublic(false);
        }
    }
}
