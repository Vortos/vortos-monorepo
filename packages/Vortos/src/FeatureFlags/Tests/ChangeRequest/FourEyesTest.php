<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\ChangeRequest;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;

final class FourEyesTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-22T10:00:00+00:00');
    }

    public function test_requestor_cannot_approve_own_request(): void
    {
        $cr = $this->pending('alice');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/4-eyes/i');
        $cr->addApproval('alice', 'self approve', $this->now);
    }

    public function test_different_actor_can_approve(): void
    {
        $cr = $this->pending('alice');

        $cr->addApproval('bob', 'reviewed', $this->now);

        $this->assertSame(ChangeRequestStatus::Approved, $cr->status());
    }

    public function test_actor_cannot_vote_twice(): void
    {
        $cr = $this->pending('alice', required: 2);
        $cr->addApproval('bob', 'first', $this->now);

        $this->expectException(\DomainException::class);
        $cr->addApproval('bob', 'again', $this->now);
    }

    public function test_actor_cannot_approve_then_reject(): void
    {
        $cr = $this->pending('alice', required: 2);
        $cr->addApproval('bob', 'first', $this->now);

        $this->expectException(\DomainException::class);
        $cr->addRejection('bob', 'changed mind', $this->now);
    }

    public function test_requestor_can_be_rejected_by_other_actor(): void
    {
        $cr = $this->pending('alice');
        $cr->addRejection('bob', 'no', $this->now);

        $this->assertSame(ChangeRequestStatus::Rejected, $cr->status());
    }

    private function pending(string $requestedBy, int $required = 1): ChangeRequest
    {
        return ChangeRequest::create(
            id: 'cr-1', flagName: 'checkout', projectId: 'default', environment: 'production',
            changeType: ChangeType::Enable, payload: [], reason: 'launch the checkout flow',
            requestedBy: $requestedBy, requestedAt: $this->now, requiredApprovals: $required,
            expiresAt: $this->now->modify('+7 days'),
        );
    }
}
