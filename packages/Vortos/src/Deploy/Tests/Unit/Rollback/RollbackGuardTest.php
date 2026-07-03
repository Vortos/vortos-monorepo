<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Rollback;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\RollbackRefusalReason;
use Vortos\Release\Schema\SchemaFingerprint;

final class RollbackGuardTest extends TestCase
{
    private function manifest(array $migrationIds): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: new SchemaFingerprint($migrationIds),
            createdAt: new \DateTimeImmutable('2026-01-01'),
        );
    }

    public function test_target_equals_applied_is_legal(): void
    {
        RollbackGuard::assertLegalPure(
            $this->manifest(['m001', 'm002']),
            new SchemaFingerprint(['m001', 'm002']),
            new KnownMigrationSet(['m001', 'm002']),
        );

        $this->addToAssertionCount(1);
    }

    public function test_target_subset_of_applied_is_legal(): void
    {
        RollbackGuard::assertLegalPure(
            $this->manifest(['m001']),
            new SchemaFingerprint(['m001', 'm002']),
            new KnownMigrationSet(['m001', 'm002']),
        );

        $this->addToAssertionCount(1);
    }

    public function test_target_not_subset_of_applied_is_refused(): void
    {
        try {
            RollbackGuard::assertLegalPure(
                $this->manifest(['m001', 'm003']),
                new SchemaFingerprint(['m001', 'm002']),
                new KnownMigrationSet(['m001', 'm002', 'm003']),
            );

            $this->fail('Expected RollbackRefusedException');
        } catch (RollbackRefusedException $e) {
            self::assertSame(RollbackRefusalReason::TargetNotSubset, $e->reason());
            self::assertContains('m003', $e->offendingMigrations());
            self::assertStringContainsString('m003', $e->getMessage());
            self::assertStringContainsString('Recovery', $e->getMessage());
        }
    }

    public function test_applied_contains_unknown_id_is_refused(): void
    {
        try {
            RollbackGuard::assertLegalPure(
                $this->manifest(['m001']),
                new SchemaFingerprint(['m001', 'm_hotfix']),
                new KnownMigrationSet(['m001']),
            );

            $this->fail('Expected RollbackRefusedException');
        } catch (RollbackRefusedException $e) {
            self::assertSame(RollbackRefusalReason::UnknownAppliedMigration, $e->reason());
            self::assertContains('m_hotfix', $e->offendingMigrations());
        }
    }

    public function test_disjoint_sets_refused(): void
    {
        $this->expectException(RollbackRefusedException::class);

        RollbackGuard::assertLegalPure(
            $this->manifest(['m_a', 'm_b']),
            new SchemaFingerprint(['m_x', 'm_y']),
            new KnownMigrationSet(['m_a', 'm_b', 'm_x', 'm_y']),
        );
    }

    public function test_overlapping_sets_refused(): void
    {
        $this->expectException(RollbackRefusedException::class);

        RollbackGuard::assertLegalPure(
            $this->manifest(['m001', 'm002', 'm003']),
            new SchemaFingerprint(['m001', 'm002']),
            new KnownMigrationSet(['m001', 'm002', 'm003']),
        );
    }

    public function test_empty_target_against_applied_is_legal(): void
    {
        RollbackGuard::assertLegalPure(
            $this->manifest([]),
            new SchemaFingerprint(['m001']),
            new KnownMigrationSet(['m001']),
        );

        $this->addToAssertionCount(1);
    }
}
