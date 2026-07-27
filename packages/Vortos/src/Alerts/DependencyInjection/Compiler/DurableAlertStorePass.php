<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;
use Vortos\Alerts\Dedupe\DbalAlertStateStore;
use Vortos\Alerts\Escalation\AckStoreInterface;
use Vortos\Alerts\Escalation\DbalAckStore;
use Vortos\Alerts\Escalation\DbalMaintenanceSilenceStore;
use Vortos\Alerts\Escalation\MaintenanceSilenceStoreInterface;

/**
 * Re-points the alert stores at their DBAL implementations once every extension has loaded.
 *
 * WHY THIS IS A PASS AND NOT PART OF AlertsExtension::load()
 * ---------------------------------------------------------
 * The extension picks between DBAL and in-memory stores with `$container->has(Connection::class)`.
 * The Connection is registered by a DIFFERENT package's extension, and Symfony gives no ordering
 * guarantee between extension loads — so that check is a race, and when it loses, alerting quietly
 * degrades to in-memory storage while reporting nothing.
 *
 * That degradation is not cosmetic. Observed in production after a successful Slack delivery:
 *   • `alerts_audit_log` stayed empty — no forensic record that an alert was ever raised.
 *   • Dedupe state lived in process memory, so it was lost on every restart and NOT shared between
 *     the app color and the scheduler node. The same condition therefore re-pages from each
 *     process and again after every deploy, which is precisely how a team learns to ignore alerts.
 *   • Acks did not persist, so acknowledging an alert did not stop the escalation.
 *   • Maintenance silences did not persist, so alerts could not be muted across a deploy — the one
 *     moment you most want them muted.
 *
 * This is the same defect as the scheduler's DeadManDetector registration
 * ({@see \Vortos\Scheduler\DependencyInjection\Compiler\DeadManDetectorPass}). Cross-package
 * availability checks belong in a compiler pass, which runs strictly after all extensions have
 * loaded and therefore observes a fact rather than a race.
 *
 * The in-memory fallback registered by the extension remains correct and intentional for a
 * container with no database at all (CLI tools, unit tests) — this pass simply upgrades to durable
 * storage whenever a Connection actually exists.
 */
final class DurableAlertStorePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(Connection::class)) {
            return; // Genuinely no database in this container; the in-memory fallback is correct.
        }

        if ($container->hasDefinition(DbalAlertStateStore::class)) {
            return; // The extension already won its race — nothing to repair.
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalAlertStateStore::class, DbalAlertStateStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'alerts_state')
            ->setPublic(false);
        $container->setAlias(AlertStateStoreInterface::class, DbalAlertStateStore::class)->setPublic(false);

        $container->register(DbalAckStore::class, DbalAckStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'alerts_acks')
            ->setPublic(false);
        $container->setAlias(AckStoreInterface::class, DbalAckStore::class)->setPublic(false);

        $container->register(DbalMaintenanceSilenceStore::class, DbalMaintenanceSilenceStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'alerts_silences')
            ->setPublic(false);
        $container->setAlias(MaintenanceSilenceStoreInterface::class, DbalMaintenanceSilenceStore::class)
            ->setPublic(false);
    }
}
