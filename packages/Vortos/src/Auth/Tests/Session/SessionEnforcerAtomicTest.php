<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Session;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Session\Contract\SessionPolicyInterface;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\Exception\SessionLimitExceededException;
use Vortos\Auth\Session\SessionEnforcementResult;
use Vortos\Auth\Session\SessionEnforcer;
use Vortos\Auth\Session\SessionLimitAction;

final class SessionEnforcerAtomicTest extends TestCase
{
    private SessionStoreInterface $store;
    private TokenStorageInterface $tokenStorage;
    private UserIdentityInterface $identity;

    protected function setUp(): void
    {
        $this->store = $this->createMock(SessionStoreInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->identity = $this->createMock(UserIdentityInterface::class);
        $this->identity->method('id')->willReturn('user-123');
    }

    public function test_no_policy_skips_enforcement(): void
    {
        $enforcer = new SessionEnforcer($this->store, $this->tokenStorage, null);
        $this->store->expects($this->never())->method('enforceAndAdd');

        $enforcer->enforceOnIssue($this->identity, 'jti-1', time(), 3600);
    }

    public function test_rejected_session_throws(): void
    {
        $policy = $this->createMock(SessionPolicyInterface::class);
        $policy->method('getMaxSessions')->willReturn(1);
        $policy->method('onLimitExceeded')->willReturn(SessionLimitAction::RejectNew);

        $this->store->method('enforceAndAdd')->willReturn(SessionEnforcementResult::rejected());

        $enforcer = new SessionEnforcer($this->store, $this->tokenStorage, $policy);

        $this->expectException(SessionLimitExceededException::class);
        $enforcer->enforceOnIssue($this->identity, 'jti-new', time(), 3600);
    }

    public function test_evicted_sessions_are_revoked(): void
    {
        $policy = $this->createMock(SessionPolicyInterface::class);
        $policy->method('getMaxSessions')->willReturn(2);
        $policy->method('onLimitExceeded')->willReturn(SessionLimitAction::InvalidateOldest);

        $this->store->method('enforceAndAdd')
            ->willReturn(SessionEnforcementResult::ok(['jti-old-1', 'jti-old-2']));

        $this->tokenStorage->expects($this->exactly(2))
            ->method('revoke')
            ->willReturnCallback(function (string $jti) {
                $this->assertContains($jti, ['jti-old-1', 'jti-old-2']);
            });

        $enforcer = new SessionEnforcer($this->store, $this->tokenStorage, $policy);
        $enforcer->enforceOnIssue($this->identity, 'jti-new', time(), 3600);
    }

    public function test_successful_add_with_no_evictions(): void
    {
        $policy = $this->createMock(SessionPolicyInterface::class);
        $policy->method('getMaxSessions')->willReturn(5);
        $policy->method('onLimitExceeded')->willReturn(SessionLimitAction::RejectNew);

        $this->store->method('enforceAndAdd')
            ->willReturn(SessionEnforcementResult::ok([]));

        $this->tokenStorage->expects($this->never())->method('revoke');

        $enforcer = new SessionEnforcer($this->store, $this->tokenStorage, $policy);
        $enforcer->enforceOnIssue($this->identity, 'jti-new', time(), 3600);
    }

    public function test_enforce_and_add_receives_correct_parameters(): void
    {
        $policy = $this->createMock(SessionPolicyInterface::class);
        $policy->method('getMaxSessions')->willReturn(3);
        $policy->method('onLimitExceeded')->willReturn(SessionLimitAction::InvalidateOldest);

        $now = time();
        $this->store->expects($this->once())
            ->method('enforceAndAdd')
            ->with('user-123', 'jti-x', $now, 7200, 3, true)
            ->willReturn(SessionEnforcementResult::ok([]));

        $enforcer = new SessionEnforcer($this->store, $this->tokenStorage, $policy);
        $enforcer->enforceOnIssue($this->identity, 'jti-x', $now, 7200);
    }
}
