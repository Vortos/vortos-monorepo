<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class SchemaCompatibilityCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_clean_delta_passes(): void
    {
        // desired m001+m002 ⊇ applied m001 — a normal expand. Known set covers applied.
        $check = new SchemaCompatibilityCheck(new PhaseGate(), new FakeManifestReadModel(knownIds: ['m001', 'm002']));

        $ctx = $this->context(
            manifest: $this->manifest(['m001', 'm002']),
            state: $this->state(['m001']),
        );

        $finding = $check->check($ctx);

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_pending_contract_fails(): void
    {
        $check = new SchemaCompatibilityCheck(new PhaseGate());

        $ctx = $this->context(state: $this->state(['m001'], pendingContract: ['m050']));

        $finding = $check->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('m050', $finding->detail);
    }

    public function test_unknown_applied_migration_fails(): void
    {
        // applied contains m999 (a manual hotfix) unknown to any manifest.
        $check = new SchemaCompatibilityCheck(new PhaseGate(), new FakeManifestReadModel(knownIds: ['m001']));

        $ctx = $this->context(
            manifest: $this->manifest(['m001']),
            state: $this->state(['m001', 'm999']),
        );

        $finding = $check->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('m999', $finding->detail);
    }

    public function test_disjoint_schema_fails(): void
    {
        $check = new SchemaCompatibilityCheck(new PhaseGate(), new FakeManifestReadModel(knownIds: ['x1', 'm001']));

        $ctx = $this->context(
            manifest: $this->manifest(['x1']),
            state: $this->state(['m001']),
        );

        $finding = $check->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('disjoint', $finding->summary);
    }

    public function test_passes_without_manifest_read_model(): void
    {
        // Optional dependency absent: gate still validates the contract phase.
        $check = new SchemaCompatibilityCheck(new PhaseGate());

        $finding = $check->check($this->context(
            manifest: $this->manifest(['m001', 'm002']),
            state: $this->state(['m001']),
        ));

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }
}
