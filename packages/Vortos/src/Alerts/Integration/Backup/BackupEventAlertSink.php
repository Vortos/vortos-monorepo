<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Backup;

use Throwable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSeverity;
use Vortos\Backup\Event\BackupEventSinkInterface;

/**
 * Implements the Block 19 {@see BackupEventSinkInterface} — the documented Block 17
 * hook (§3.7): `backup.failed` / `backup.integrity_failed` become a `Critical`
 * {@see AlertEvent}. Zero change to Block 19; this adapter is registered only when
 * `vortos-backup` is installed (class-existence guarded in {@see \Vortos\Alerts\DependencyInjection\AlertsExtension}).
 *
 * Implementations of {@see BackupEventSinkInterface} MUST NOT throw — a broken
 * alerter must never fail (or mask) a backup.
 */
final class BackupEventAlertSink implements BackupEventSinkInterface
{
    public function __construct(
        private readonly AlertDispatcherInterface $dispatcher,
        /**
         * Floor for what reaches a human. Defaults to Warning, so successes do not.
         *
         * A backup lifecycle emits Info on every success — each backup, each retention pass, each
         * restore drill — and routing those to a chat channel is alert fatigue with nothing bought
         * for it. "I stopped seeing the success message" is not something people reliably notice,
         * and it is already covered mechanically and far better by the freshness dead-man, which
         * pages when a backup STOPS ARRIVING rather than when one fails.
         *
         * Successes are not discarded; they are recorded where success belongs — the drill report
         * table and the backup gauges (backup_last_success_age_seconds,
         * backup_drill_last_outcome, backup_drill_last_age_seconds). Those are queryable, graphable
         * and alertable on absence, which a chat message is not.
         *
         * Overridable, because an installation with no metrics pipeline may genuinely want the
         * chatter as its only evidence of life.
         */
        private readonly Severity $minimumSeverity = Severity::Warning,
    ) {}

    public function emit(BackupEvent $event): void
    {
        $severity = $this->mapSeverity($event->severity);

        if ($severity->rank() < $this->minimumSeverity->rank()) {
            return;
        }

        try {
            $this->dispatcher->dispatch(AlertEvent::scrubbed(
                ruleId: $event->type,
                severity: $severity,
                title: sprintf('Backup event: %s', $event->type),
                summary: $event->message . ($event->error !== null ? ' — ' . $event->error : ''),
                source: AlertSource::Backup,
                env: $event->environment,
                tenantId: null,
                labels: ['engine' => $event->engine->value, 'type' => $event->type],
                annotations: [],
                links: [],
                occurredAt: $event->occurredAt,
            ));
        } catch (Throwable) {
            // Never fail (or mask) a backup because the alerter broke.
        }
    }

    private function mapSeverity(BackupEventSeverity $severity): Severity
    {
        return match ($severity) {
            BackupEventSeverity::Info => Severity::Info,
            BackupEventSeverity::Warning => Severity::Warning,
            BackupEventSeverity::Critical => Severity::Critical,
        };
    }
}
