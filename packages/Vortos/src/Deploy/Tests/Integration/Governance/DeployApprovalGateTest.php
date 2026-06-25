<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration\Governance;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\Governance\ChangeRequestDeployApprovalGate;
use Vortos\Deploy\Credential\Governance\DeployChangeRequest;
use Vortos\Deploy\Credential\Governance\EnvironmentProtectionConfig;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialGovernanceException;
use Vortos\Deploy\Tests\Fixtures\FakeDeployChangeRequestStore;

final class DeployApprovalGateTest extends TestCase
{
    public function test_unprotected_env_passes_through(): void
    {
        $gate = $this->makeGate([
            'staging' => new EnvironmentProtectionConfig('staging', false),
        ]);

        $gate->assertApproved(new EnvironmentName('staging'), 'deployer-1');

        $this->addToAssertionCount(1);
    }

    public function test_protected_env_no_approved_cr_rejected(): void
    {
        $gate = $this->makeGate([
            'prod' => new EnvironmentProtectionConfig('prod', true, 1),
        ]);

        $this->expectException(CredentialGovernanceException::class);
        $this->expectExceptionMessage('requires an approved change request');

        $gate->assertApproved(new EnvironmentName('prod'), 'deployer-1');
    }

    public function test_protected_env_with_approved_cr_passes(): void
    {
        $store = new FakeDeployChangeRequestStore();
        $store->setApproved('prod', new DeployChangeRequest(
            id: 'cr-1',
            environment: 'prod',
            requestedBy: 'deployer-1',
            approvedBy: 'reviewer-1',
            approvedAt: new \DateTimeImmutable(),
        ));

        $gate = $this->makeGate([
            'prod' => new EnvironmentProtectionConfig('prod', true, 1),
        ], $store);

        $gate->assertApproved(new EnvironmentName('prod'), 'deployer-1');

        $this->addToAssertionCount(1);
    }

    public function test_self_approval_rejected(): void
    {
        $store = new FakeDeployChangeRequestStore();
        $store->setApproved('prod', new DeployChangeRequest(
            id: 'cr-1',
            environment: 'prod',
            requestedBy: 'same-person',
            approvedBy: 'same-person',
            approvedAt: new \DateTimeImmutable(),
        ));

        $gate = $this->makeGate([
            'prod' => new EnvironmentProtectionConfig('prod', true, 1),
        ], $store);

        $this->expectException(CredentialGovernanceException::class);
        $this->expectExceptionMessage('Self-approval');

        $gate->assertApproved(new EnvironmentName('prod'), 'same-person');
    }

    public function test_unknown_env_not_protected_passes_through(): void
    {
        $gate = $this->makeGate([
            'prod' => new EnvironmentProtectionConfig('prod', true, 1),
        ]);

        $gate->assertApproved(new EnvironmentName('dev'), 'deployer-1');

        $this->addToAssertionCount(1);
    }

    public function test_protected_env_zero_required_approvals_allows_self(): void
    {
        $store = new FakeDeployChangeRequestStore();
        $store->setApproved('prod', new DeployChangeRequest(
            id: 'cr-1',
            environment: 'prod',
            requestedBy: 'solo-deployer',
            approvedBy: 'solo-deployer',
            approvedAt: new \DateTimeImmutable(),
        ));

        $gate = $this->makeGate([
            'prod' => new EnvironmentProtectionConfig('prod', true, 0),
        ], $store);

        $gate->assertApproved(new EnvironmentName('prod'), 'solo-deployer');

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, EnvironmentProtectionConfig> $protections
     */
    private function makeGate(array $protections, ?FakeDeployChangeRequestStore $store = null): ChangeRequestDeployApprovalGate
    {
        return new ChangeRequestDeployApprovalGate(
            protectedEnvironments: $protections,
            store: $store ?? new FakeDeployChangeRequestStore(),
        );
    }
}
