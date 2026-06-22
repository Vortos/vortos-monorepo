<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;

final class QuorumTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');
    }

    public function test_quorum_of_one_approves_on_first_vote(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);

        $this->assertSame(ChangeRequestStatus::Approved, $cr->status());
    }

    public function test_quorum_of_two_stays_pending_after_one_vote(): void
    {
        $cr = $this->pending(required: 2);
        $cr->addApproval('bob', 'first', $this->now);

        $this->assertSame(ChangeRequestStatus::Pending, $cr->status());
        $this->assertCount(1, $cr->approvals());
    }

    public function test_quorum_of_two_approves_after_two_distinct_votes(): void
    {
        $cr = $this->pending(required: 2);
        $cr->addApproval('bob', 'first', $this->now);
        $cr->addApproval('carol', 'second', $this->now);

        $this->assertSame(ChangeRequestStatus::Approved, $cr->status());
        $this->assertCount(2, $cr->approvals());
    }

    public function test_rejection_overrides_partial_quorum(): void
    {
        $cr = $this->pending(required: 2);
        $cr->addApproval('bob', 'first', $this->now);
        $cr->addRejection('carol', 'veto', $this->now);

        $this->assertSame(ChangeRequestStatus::Rejected, $cr->status());
    }

    private function pending(int $required): ChangeRequest
    {
        return ChangeRequest::create(
            id: 'cr-1', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'launch the checkout flow',
            requestedBy: 'alice', requestedAt: $this->now, requiredApprovals: $required,
            expiresAt: $this->now->modify('+7 days'),
        );
    }
}
