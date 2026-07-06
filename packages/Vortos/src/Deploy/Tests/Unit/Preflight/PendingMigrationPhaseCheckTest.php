<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\PendingMigrationPhaseCheck;
use Vortos\Deploy\Tests\Fixtures\FakeMigrationPhaseReader;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Migration\Schema\MigrationPhase;

final class PendingMigrationPhaseCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_fails_on_destructive_unannotated_pending_migration(): void
    {
        // Pending m002 is destructive & un-annotated (applied has only m001).
        $reader = new FakeMigrationPhaseReader([], ['m002']);
        $check = new PendingMigrationPhaseCheck($reader);

        $finding = $check->check($this->context(
            manifest: $this->manifest(['m001', 'm002']),
            state: $this->state(['m001']),
        ));

        $this->assertTrue($finding->isFailure());
        $this->assertStringContainsString('destructive', $finding->toArray()['summary'] ?? '');
    }

    public function test_passes_when_pending_is_phase_safe(): void
    {
        $reader = new FakeMigrationPhaseReader(); // all Expand, none destructive
        $check = new PendingMigrationPhaseCheck($reader);

        $finding = $check->check($this->context(
            manifest: $this->manifest(['m001', 'm002']),
            state: $this->state(['m001']),
        ));

        $this->assertFalse($finding->isFailure());
    }

    public function test_passes_and_reports_pending_contract(): void
    {
        $reader = new FakeMigrationPhaseReader(['m002' => MigrationPhase::Contract]);
        $check = new PendingMigrationPhaseCheck($reader);

        $finding = $check->check($this->context(
            manifest: $this->manifest(['m001', 'm002']),
            state: $this->state(['m001']),
        ));

        $this->assertFalse($finding->isFailure());
        $this->assertStringContainsString('m002', $finding->toArray()['detail'] ?? '');
    }

    public function test_passes_when_nothing_pending(): void
    {
        $reader = new FakeMigrationPhaseReader();
        $check = new PendingMigrationPhaseCheck($reader);

        $finding = $check->check($this->context(
            manifest: $this->manifest(['m001']),
            state: $this->state(['m001']),
        ));

        $this->assertFalse($finding->isFailure());
    }
}
