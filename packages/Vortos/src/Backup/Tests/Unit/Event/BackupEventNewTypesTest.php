<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Event;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSeverity;

final class BackupEventNewTypesTest extends TestCase
{
    public function test_drill_succeeded_is_info(): void
    {
        $event = BackupEvent::drillSucceeded(DatabaseEngine::Postgres, 'prod', 15000, new DateTimeImmutable());
        $this->assertSame(BackupEvent::TYPE_DRILL_SUCCEEDED, $event->type);
        $this->assertSame(BackupEventSeverity::Info, $event->severity);
        $this->assertStringContainsString('15000ms', $event->message);
    }

    public function test_drill_failed_is_critical(): void
    {
        $event = BackupEvent::drillFailed(DatabaseEngine::Postgres, 'prod', 'bad thing', new DateTimeImmutable());
        $this->assertSame(BackupEvent::TYPE_DRILL_FAILED, $event->type);
        $this->assertSame(BackupEventSeverity::Critical, $event->severity);
    }

    public function test_replication_failed_is_critical(): void
    {
        $event = BackupEvent::replicationFailed(DatabaseEngine::Postgres, 'prod', 'err', new DateTimeImmutable());
        $this->assertSame(BackupEvent::TYPE_REPLICATION_FAILED, $event->type);
        $this->assertSame(BackupEventSeverity::Critical, $event->severity);
    }

    public function test_immutability_violation_is_critical(): void
    {
        $event = BackupEvent::immutabilityViolation(DatabaseEngine::Postgres, 'prod', 'delete succeeded', new DateTimeImmutable());
        $this->assertSame(BackupEvent::TYPE_IMMUTABILITY_VIOLATION, $event->type);
        $this->assertSame(BackupEventSeverity::Critical, $event->severity);
    }
}
