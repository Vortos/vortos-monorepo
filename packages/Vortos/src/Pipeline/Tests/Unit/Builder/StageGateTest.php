<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Model\StageKind;

final class StageGateTest extends TestCase
{
    public function test_test_stage_always_emits(): void
    {
        $gate = new StageGate();
        $this->assertTrue($gate->shouldEmit(StageKind::Test));
    }

    public function test_static_analysis_always_emits(): void
    {
        $gate = new StageGate();
        $this->assertTrue($gate->shouldEmit(StageKind::StaticAnalysis));
    }

    public function test_agnosticism_always_emits(): void
    {
        $gate = new StageGate();
        $this->assertTrue($gate->shouldEmit(StageKind::Agnosticism));
    }

    public function test_deploy_always_emits(): void
    {
        $gate = new StageGate();
        $this->assertTrue($gate->shouldEmit(StageKind::Deploy));
    }

    public function test_split_always_emits(): void
    {
        $gate = new StageGate();
        $this->assertTrue($gate->shouldEmit(StageKind::Split));
    }

    public function test_security_gated_by_default(): void
    {
        $gate = new StageGate();
        $this->assertFalse($gate->shouldEmit(StageKind::Security));
    }

    public function test_migration_dry_run_gated_by_default(): void
    {
        $gate = new StageGate();
        $this->assertFalse($gate->shouldEmit(StageKind::MigrationDryRun));
    }

    public function test_build_gated_by_default(): void
    {
        $gate = new StageGate();
        $this->assertFalse($gate->shouldEmit(StageKind::Build));
    }

    public function test_iac_plan_gated_by_default(): void
    {
        $gate = new StageGate();
        $this->assertFalse($gate->shouldEmit(StageKind::IacPlan));
    }

    public function test_enabled_future_stage_emits(): void
    {
        $gate = new StageGate([StageKind::Security->value]);
        $this->assertTrue($gate->shouldEmit(StageKind::Security));
    }

    public function test_enabled_future_stages_multiple(): void
    {
        $gate = new StageGate([StageKind::Security->value, StageKind::Build->value]);
        $this->assertTrue($gate->shouldEmit(StageKind::Security));
        $this->assertTrue($gate->shouldEmit(StageKind::Build));
        $this->assertFalse($gate->shouldEmit(StageKind::MigrationDryRun));
    }

    public function test_gated_stages_returns_gated_out_stages(): void
    {
        $gate = new StageGate();
        $gate->shouldEmit(StageKind::Security);
        $gate->shouldEmit(StageKind::Build);

        $gated = $gate->gatedStages();

        $this->assertCount(2, $gated);
        $this->assertContains(StageKind::Security, $gated);
        $this->assertContains(StageKind::Build, $gated);
    }

    public function test_gated_stages_is_cumulative(): void
    {
        $gate = new StageGate();
        $gate->shouldEmit(StageKind::Security);
        $this->assertCount(1, $gate->gatedStages());

        $gate->shouldEmit(StageKind::Build);
        $this->assertCount(2, $gate->gatedStages());
    }

    public function test_gated_stages_does_not_include_emitted_stages(): void
    {
        $gate = new StageGate();
        $gate->shouldEmit(StageKind::Test);
        $gate->shouldEmit(StageKind::Deploy);
        $gate->shouldEmit(StageKind::Security);

        $gated = $gate->gatedStages();

        $this->assertNotContains(StageKind::Test, $gated);
        $this->assertNotContains(StageKind::Deploy, $gated);
        $this->assertContains(StageKind::Security, $gated);
    }

    public function test_gated_stages_does_not_duplicate(): void
    {
        $gate = new StageGate();
        $gate->shouldEmit(StageKind::Security);
        $gate->shouldEmit(StageKind::Security);

        $this->assertCount(1, $gate->gatedStages());
    }

    public function test_gated_stages_empty_initially(): void
    {
        $gate = new StageGate();
        $this->assertSame([], $gate->gatedStages());
    }
}
