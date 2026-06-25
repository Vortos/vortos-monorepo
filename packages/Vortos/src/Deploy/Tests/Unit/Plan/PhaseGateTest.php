<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\ContractInSameDeployException;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Schema\SchemaFingerprint;

final class PhaseGateTest extends TestCase
{
    private PhaseGate $gate;

    protected function setUp(): void
    {
        $this->gate = new PhaseGate();
    }

    private function state(array $pendingContractMigrations = []): CurrentDeployState
    {
        return new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('a', 64),
            appliedFingerprint: new SchemaFingerprint(['m001']),
            pendingContractMigrations: $pendingContractMigrations,
        );
    }

    public function test_no_pending_contracts_passes(): void
    {
        $this->gate->assertNoPendingContract($this->state([]));

        $this->addToAssertionCount(1);
    }

    public function test_pending_contract_throws(): void
    {
        $this->expectException(ContractInSameDeployException::class);
        $this->expectExceptionMessageMatches('/m_drop_x/');

        $this->gate->assertNoPendingContract($this->state(['m_drop_x']));
    }

    public function test_exception_carries_offending_ids(): void
    {
        try {
            $this->gate->assertNoPendingContract($this->state(['m_drop_a', 'm_drop_b']));
            $this->fail('Expected ContractInSameDeployException');
        } catch (ContractInSameDeployException $e) {
            self::assertSame(['m_drop_a', 'm_drop_b'], $e->offendingMigrations);
            self::assertStringContainsString('m_drop_a', $e->getMessage());
            self::assertStringContainsString('m_drop_b', $e->getMessage());
            self::assertStringContainsString('soak/flag gate', $e->getMessage());
        }
    }

    public function test_expand_only_pending_passes(): void
    {
        $this->gate->assertNoPendingContract($this->state([]));

        $this->addToAssertionCount(1);
    }

    public function test_first_deploy_passes(): void
    {
        $state = CurrentDeployState::firstDeploy();

        $this->gate->assertNoPendingContract($state);

        $this->addToAssertionCount(1);
    }

    public function test_exception_message_includes_recovery_instructions(): void
    {
        try {
            $this->gate->assertNoPendingContract($this->state(['m_contract_1']));
            $this->fail('Expected exception');
        } catch (ContractInSameDeployException $e) {
            self::assertStringContainsString('later deploy', $e->getMessage());
        }
    }
}
