<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\PhaseOrderPolicy;
use Vortos\Deploy\Plan\StepAction;

final class PhaseOrderPolicyTest extends TestCase
{
    private PhaseOrderPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PhaseOrderPolicy();
    }

    public function test_canonical_order_accepted(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::ExpandMigrate, [$this->noop()]),
            new DeployPhase(PhaseKind::RollWorkers, [$this->noop()]),
            new DeployPhase(PhaseKind::StageColor, [$this->noop()]),
            new DeployPhase(PhaseKind::HealthGate, [$this->noop()]),
            new DeployPhase(PhaseKind::Smoke, [$this->noop()]),
            new DeployPhase(PhaseKind::Cutover, [$this->noop()]),
            new DeployPhase(PhaseKind::Promote, [$this->noop()]),
        ];

        $this->policy->assertValid($phases);
        $this->addToAssertionCount(1);
    }

    public function test_workers_after_cutover_rejected(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::ExpandMigrate, [$this->noop()]),
            new DeployPhase(PhaseKind::StageColor, [$this->noop()]),
            new DeployPhase(PhaseKind::Cutover, [$this->noop()]),
            new DeployPhase(PhaseKind::RollWorkers, [$this->noop()]),
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RollWorkers must precede Cutover');

        $this->policy->assertValid($phases);
    }

    public function test_workers_after_stage_color_rejected(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::ExpandMigrate, [$this->noop()]),
            new DeployPhase(PhaseKind::StageColor, [$this->noop()]),
            new DeployPhase(PhaseKind::RollWorkers, [$this->noop()]),
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RollWorkers must precede StageColor');

        $this->policy->assertValid($phases);
    }

    public function test_no_worker_phase_is_valid(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::StageColor, [$this->noop()]),
            new DeployPhase(PhaseKind::Cutover, [$this->noop()]),
        ];

        $this->policy->assertValid($phases);
        $this->addToAssertionCount(1);
    }

    public function test_no_cutover_phase_is_valid(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::RollWorkers, [$this->noop()]),
            new DeployPhase(PhaseKind::StageColor, [$this->noop()]),
        ];

        $this->policy->assertValid($phases);
        $this->addToAssertionCount(1);
    }

    public function test_workers_before_cutover_is_valid(): void
    {
        $phases = [
            new DeployPhase(PhaseKind::RollWorkers, [$this->noop()]),
            new DeployPhase(PhaseKind::Cutover, [$this->noop()]),
        ];

        $this->policy->assertValid($phases);
        $this->addToAssertionCount(1);
    }

    private function noop(): DeployStep
    {
        return new DeployStep(StepAction::Noop, 'no-op');
    }
}
