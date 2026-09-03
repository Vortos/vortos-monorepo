<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreCoordinator;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\Backup\Restore\RestoreTargetRegistry;
use Vortos\Backup\Tests\Support\FakeRestoreTarget;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Resolution used to key on engine alone, which made a second Postgres target unreachable however
 * it was registered: a `physical_base` would still have gone to the logical target, which honestly
 * declares it cannot do point-in-time recovery.
 */
final class RestoreTargetResolutionTest extends TestCase
{
    private function pitrTarget(): RestoreTargetInterface
    {
        return new class implements RestoreTargetInterface {
            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([
                    RestoreTargetCapability::PointInTime->value => true,
                ]);
            }

            public function engine(): DatabaseEngine
            {
                return DatabaseEngine::Postgres;
            }

            public function restore(iterable $chunks, RestoreRequest $request): void {}
        };
    }

    private function coordinator(bool $withPitr): RestoreCoordinator
    {
        $targets = ['postgres' => fn () => new FakeRestoreTarget()];
        if ($withPitr) {
            $targets['postgres-pitr'] = fn () => $this->pitrTarget();
        }

        return new RestoreCoordinator(
            new RestoreTargetRegistry(new ServiceLocator($targets)),
            new EnvelopeStreamCipher(),
            null,
        );
    }

    public function testABaseBackupResolvesToThePointInTimeTarget(): void
    {
        $target = $this->coordinator(true)->targetFor(DatabaseEngine::Postgres, BackupKind::PhysicalBase);

        self::assertTrue($target->capabilities()->supports(RestoreTargetCapability::PointInTime));
    }

    public function testALogicalDumpResolvesToTheOrdinaryTargetEvenWhenAPitrTargetExists(): void
    {
        $target = $this->coordinator(true)->targetFor(DatabaseEngine::Postgres, BackupKind::LogicalFull);

        self::assertInstanceOf(FakeRestoreTarget::class, $target);
    }

    /**
     * Every existing single-target installation must keep behaving exactly as it did.
     */
    public function testFallsBackToTheEngineTargetWhenNoPitrVariantIsRegistered(): void
    {
        $coordinator = $this->coordinator(false);

        self::assertInstanceOf(
            FakeRestoreTarget::class,
            $coordinator->targetFor(DatabaseEngine::Postgres, BackupKind::PhysicalBase),
        );
        self::assertInstanceOf(
            FakeRestoreTarget::class,
            $coordinator->targetFor(DatabaseEngine::Postgres),
        );
    }
}
