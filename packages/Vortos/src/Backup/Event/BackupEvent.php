<?php

declare(strict_types=1);

namespace Vortos\Backup\Event;

use DateTimeImmutable;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * A backup lifecycle event broadcast on the {@see BackupEventSinkInterface} seam.
 *
 * This is the decoupling point from Block 17 (`vortos-alerts`): Block 19 emits typed
 * events (a failed backup is `Critical`); the in-core logging sink records them now,
 * and a Block-17 alerting sink later turns `Critical` into a page — with zero changes
 * here.
 */
final readonly class BackupEvent
{
    public const TYPE_SUCCEEDED = 'backup.succeeded';
    public const TYPE_FAILED = 'backup.failed';
    public const TYPE_INTEGRITY_FAILED = 'backup.integrity_failed';
    public const TYPE_RETENTION_APPLIED = 'backup.retention_applied';
    public const TYPE_DRILL_SUCCEEDED = 'backup.drill_succeeded';
    public const TYPE_DRILL_FAILED = 'backup.drill_failed';
    public const TYPE_REPLICATION_FAILED = 'backup.replication_failed';
    public const TYPE_IMMUTABILITY_VIOLATION = 'backup.immutability_violation';

    public function __construct(
        public string $type,
        public BackupEventSeverity $severity,
        public DatabaseEngine $engine,
        public string $environment,
        public string $message,
        public DateTimeImmutable $occurredAt,
        public ?BackupArtifact $artifact = null,
        public ?string $error = null,
    ) {}

    public static function succeeded(BackupArtifact $artifact, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_SUCCEEDED,
            BackupEventSeverity::Info,
            $artifact->engine,
            $artifact->environment,
            sprintf('Backup %s stored (%d bytes).', $artifact->id->value(), $artifact->sizeBytes),
            $at,
            $artifact,
        );
    }

    public static function failed(DatabaseEngine $engine, string $environment, string $error, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_FAILED,
            BackupEventSeverity::Critical,
            $engine,
            $environment,
            sprintf('Backup failed for %s/%s.', $engine->value, $environment),
            $at,
            null,
            $error,
        );
    }

    public static function integrityFailed(DatabaseEngine $engine, string $environment, string $error, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_INTEGRITY_FAILED,
            BackupEventSeverity::Critical,
            $engine,
            $environment,
            sprintf('Backup integrity verification failed for %s/%s.', $engine->value, $environment),
            $at,
            null,
            $error,
        );
    }

    public static function retentionApplied(DatabaseEngine $engine, string $environment, int $deleted, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_RETENTION_APPLIED,
            BackupEventSeverity::Info,
            $engine,
            $environment,
            sprintf('Retention applied for %s/%s: %d artifact(s) deleted.', $engine->value, $environment, $deleted),
            $at,
        );
    }

    public static function drillSucceeded(DatabaseEngine $engine, string $environment, int $rtoMs, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_DRILL_SUCCEEDED,
            BackupEventSeverity::Info,
            $engine,
            $environment,
            sprintf('Restore drill succeeded for %s/%s (RTO: %dms).', $engine->value, $environment, $rtoMs),
            $at,
        );
    }

    public static function drillFailed(DatabaseEngine $engine, string $environment, string $error, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_DRILL_FAILED,
            BackupEventSeverity::Critical,
            $engine,
            $environment,
            sprintf('Restore drill FAILED for %s/%s.', $engine->value, $environment),
            $at,
            null,
            $error,
        );
    }

    public static function replicationFailed(DatabaseEngine $engine, string $environment, string $error, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_REPLICATION_FAILED,
            BackupEventSeverity::Critical,
            $engine,
            $environment,
            sprintf('Secondary replication failed for %s/%s.', $engine->value, $environment),
            $at,
            null,
            $error,
        );
    }

    public static function immutabilityViolation(DatabaseEngine $engine, string $environment, string $detail, DateTimeImmutable $at): self
    {
        return new self(
            self::TYPE_IMMUTABILITY_VIOLATION,
            BackupEventSeverity::Critical,
            $engine,
            $environment,
            sprintf('Immutability violation for %s/%s: %s.', $engine->value, $environment, $detail),
            $at,
            null,
            $detail,
        );
    }
}
