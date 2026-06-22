<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;

final class ChangeRequestTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');
    }

    public function test_create_starts_pending(): void
    {
        $cr = $this->pending();

        $this->assertSame(ChangeRequestStatus::Pending, $cr->status());
        $this->assertSame([], $cr->approvals());
        $this->assertSame([], $cr->rejections());
    }

    public function test_create_rejects_short_reason(): void
    {
        $this->expectException(\DomainException::class);

        ChangeRequest::create(
            id: 'cr-1', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'too short',
            requestedBy: 'alice', requestedAt: $this->now, requiredApprovals: 1,
            expiresAt: $this->now->modify('+7 days'),
        );
    }

    public function test_pending_to_approved_when_quorum_met(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'looks good', $this->now);

        $this->assertSame(ChangeRequestStatus::Approved, $cr->status());
        $this->assertCount(1, $cr->approvals());
    }

    public function test_pending_to_rejected(): void
    {
        $cr = $this->pending();
        $cr->addRejection('bob', 'not now', $this->now);

        $this->assertSame(ChangeRequestStatus::Rejected, $cr->status());
        $this->assertCount(1, $cr->rejections());
    }

    public function test_pending_to_cancelled(): void
    {
        $cr = $this->pending();
        $cr->cancel('alice');

        $this->assertSame(ChangeRequestStatus::Cancelled, $cr->status());
    }

    public function test_pending_to_expired(): void
    {
        $cr = $this->pending();
        $cr->markExpired();

        $this->assertSame(ChangeRequestStatus::Expired, $cr->status());
    }

    public function test_approved_to_applied(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);

        $cr->markApplied('system', $this->now);

        $this->assertSame(ChangeRequestStatus::Applied, $cr->status());
        $this->assertSame('system', $cr->appliedBy());
        $this->assertEquals($this->now, $cr->appliedAt());
    }

    public function test_approved_to_cancelled(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);

        $cr->cancel('alice');

        $this->assertSame(ChangeRequestStatus::Cancelled, $cr->status());
    }

    public function test_approved_to_expired(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);

        $cr->markExpired();

        $this->assertSame(ChangeRequestStatus::Expired, $cr->status());
    }

    public function test_cannot_apply_when_not_approved(): void
    {
        $cr = $this->pending();

        $this->expectException(\DomainException::class);
        $cr->markApplied('system', $this->now);
    }

    public function test_cannot_vote_after_applied(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);
        $cr->markApplied('system', $this->now);

        $this->expectException(\DomainException::class);
        $cr->addApproval('carol', 'late', $this->now);
    }

    public function test_cannot_cancel_after_rejected(): void
    {
        $cr = $this->pending();
        $cr->addRejection('bob', 'no', $this->now);

        $this->expectException(\DomainException::class);
        $cr->cancel('alice');
    }

    public function test_cannot_expire_after_applied(): void
    {
        $cr = $this->pending(required: 1);
        $cr->addApproval('bob', 'go', $this->now);
        $cr->markApplied('system', $this->now);

        $this->expectException(\DomainException::class);
        $cr->markExpired();
    }

    public function test_status_transition_matrix(): void
    {
        $this->assertTrue(ChangeRequestStatus::Pending->canTransitionTo(ChangeRequestStatus::Approved));
        $this->assertTrue(ChangeRequestStatus::Pending->canTransitionTo(ChangeRequestStatus::Rejected));
        $this->assertTrue(ChangeRequestStatus::Approved->canTransitionTo(ChangeRequestStatus::Applied));
        $this->assertFalse(ChangeRequestStatus::Applied->canTransitionTo(ChangeRequestStatus::Approved));
        $this->assertFalse(ChangeRequestStatus::Rejected->canTransitionTo(ChangeRequestStatus::Applied));
        $this->assertFalse(ChangeRequestStatus::Pending->canTransitionTo(ChangeRequestStatus::Applied));
    }

    private function pending(int $required = 1, string $requestedBy = 'alice'): ChangeRequest
    {
        return ChangeRequest::create(
            id: 'cr-1', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: ['reason' => 'launch'], reason: 'launch the checkout flow',
            requestedBy: $requestedBy, requestedAt: $this->now, requiredApprovals: $required,
            expiresAt: $this->now->modify('+7 days'),
        );
    }
}
