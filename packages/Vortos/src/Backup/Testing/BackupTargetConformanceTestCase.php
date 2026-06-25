<?php

declare(strict_types=1);

namespace Vortos\Backup\Testing;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Port\BackupTargetInterface;
use Vortos\Backup\Port\Capability\BackupTargetCapability;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The TCK every {@see BackupTargetInterface} driver must pass — the universal OpsKit
 * contract plus the backup-target contract, including the anti-drift negative case:
 * asking for a kind the driver does not support raises
 * {@see \Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException}, never a silent
 * no-op.
 *
 * A concrete driver test supplies {@see createDriver()}, {@see expectedEngine()}, and a
 * {@see unsupportedKind()} the driver genuinely cannot produce.
 */
abstract class BackupTargetConformanceTestCase extends ConformanceTestCase
{
    abstract protected function expectedEngine(): DatabaseEngine;

    abstract protected function unsupportedKind(): BackupKind;

    final protected function target(): BackupTargetInterface
    {
        $driver = $this->createDriver();
        self::assertInstanceOf(BackupTargetInterface::class, $driver);

        return $driver;
    }

    final public function test_engine_matches(): void
    {
        self::assertSame($this->expectedEngine(), $this->target()->engine());
    }

    final public function test_streaming_capability_is_declared(): void
    {
        self::assertTrue(
            $this->target()->capabilities()->supports(BackupTargetCapability::Streaming),
            'A backup target must stream (bounded memory).',
        );
    }

    final public function test_unsupported_kind_is_rejected_not_silently_degraded(): void
    {
        $target = $this->target();
        $request = new BackupRequest($this->expectedEngine(), $this->unsupportedKind(), 'test');

        $this->assertRejectsUnsupportedCapability(static fn () => $target->dump($request));
    }
}
