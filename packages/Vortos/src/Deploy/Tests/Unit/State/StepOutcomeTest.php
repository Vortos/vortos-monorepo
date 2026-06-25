<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\State;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;

final class StepOutcomeTest extends TestCase
{
    public function test_construction(): void
    {
        $outcome = new StepOutcome(
            stepIndex: 3,
            action: StepAction::CheckHealth,
            status: StepStatus::Success,
            result: 'healthy after 2 attempts',
        );

        $this->assertSame(3, $outcome->stepIndex);
        $this->assertSame(StepAction::CheckHealth, $outcome->action);
        $this->assertSame(StepStatus::Success, $outcome->status);
    }

    public function test_serialization_round_trip(): void
    {
        $outcome = new StepOutcome(1, StepAction::PullImage, StepStatus::Success, 'pulled');
        $data = $outcome->toArray();
        $restored = StepOutcome::fromArray($data);

        $this->assertSame(1, $restored->stepIndex);
        $this->assertSame(StepAction::PullImage, $restored->action);
        $this->assertSame(StepStatus::Success, $restored->status);
        $this->assertSame('pulled', $restored->result);
    }

    public function test_default_result_is_empty(): void
    {
        $outcome = new StepOutcome(0, StepAction::Noop, StepStatus::Success);
        $this->assertSame('', $outcome->result);
    }
}
