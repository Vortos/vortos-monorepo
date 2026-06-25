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
    ) {}

    public function emit(BackupEvent $event): void
    {
        try {
            $this->dispatcher->dispatch(AlertEvent::scrubbed(
                ruleId: $event->type,
                severity: $this->mapSeverity($event->severity),
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
