<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\State;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;

final class DeployRunTest extends TestCase
{
    public function test_construction_defaults(): void
    {
        $run = new DeployRun(
            runId: 'run-1',
            env: 'production',
            planHash: 'sha256:abc',
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );

        $this->assertSame('run-1', $run->runId);
        $this->assertSame(DeployStatus::Pending, $run->status);
        $this->assertSame(0, $run->completedStepCount());
    }

    public function test_step_completion_tracking(): void
    {
        $run = new DeployRun('run-1', 'prod', 'hash', 'def', 'digest');
        $this->assertFalse($run->isStepCompleted(0));

        $run->addOutcome(new StepOutcome(0, StepAction::PullImage, StepStatus::Success));
        $this->assertTrue($run->isStepCompleted(0));
        $this->assertFalse($run->isStepCompleted(1));
    }

    public function test_failed_step_not_counted_as_completed(): void
    {
        $run = new DeployRun('run-1', 'prod', 'hash', 'def', 'digest');
        $run->addOutcome(new StepOutcome(0, StepAction::CheckHealth, StepStatus::Failed));

        $this->assertFalse($run->isStepCompleted(0));
        $this->assertSame(0, $run->completedStepCount());
    }

    public function test_serialization_round_trip(): void
    {
        $run = new DeployRun('run-1', 'prod', 'sha256:plan', 'sha256:def', 'sha256:' . str_repeat('ab', 32));
        $run->status = DeployStatus::Running;
        $run->addOutcome(new StepOutcome(0, StepAction::PullImage, StepStatus::Success, 'pulled'));

        $data = $run->toArray();
        $restored = DeployRun::fromArray($data);

        $this->assertSame('run-1', $restored->runId);
        $this->assertSame(DeployStatus::Running, $restored->status);
        $this->assertTrue($restored->isStepCompleted(0));
        $this->assertSame(1, $restored->completedStepCount());
    }
}
