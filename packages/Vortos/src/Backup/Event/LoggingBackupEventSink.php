<?php

declare(strict_types=1);

namespace Vortos\Backup\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The in-core default sink: records every backup event via PSR-3 at a severity-mapped
 * level. Block 17 later registers an alerting sink alongside this one (the logging
 * trail always remains).
 */
final class LoggingBackupEventSink implements BackupEventSinkInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function emit(BackupEvent $event): void
    {
        $level = match ($event->severity) {
            BackupEventSeverity::Critical => LogLevel::CRITICAL,
            BackupEventSeverity::Warning => LogLevel::WARNING,
            BackupEventSeverity::Info => LogLevel::INFO,
        };

        $this->logger->log($level, $event->message, [
            'type' => $event->type,
            'engine' => $event->engine->value,
            'environment' => $event->environment,
            'backup_id' => $event->artifact?->id->value(),
            'error' => $event->error,
            'occurred_at' => $event->occurredAt->format(DATE_ATOM),
        ]);
    }
}
