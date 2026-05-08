<?php
declare(strict_types=1);

namespace Vortos\Auth\Session;

use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Session\Contract\SessionPolicyInterface;
use Vortos\Auth\Session\Exception\SessionLimitExceededException;
use Vortos\Auth\Session\Storage\RedisSessionStore;

/**
 * Enforces concurrent session limits on token issuance.
 *
 * Called by JwtService::issue() before storing a new refresh token.
 * If the user is at their limit, the configured policy action decides whether
 * to kick the oldest session or reject the new login.
 *
 * If no SessionPolicyInterface is registered, session limiting is disabled.
 */
final class SessionEnforcer
{
    public function __construct(
        private RedisSessionStore $store,
        private TokenStorageInterface $tokenStorage,
        private ?SessionPolicyInterface $policy,
    ) {}

    /**
     * Check and enforce session limits before a new token is issued.
     * Adds the new session if enforcement passes.
     *
     * @throws SessionLimitExceededException If the policy rejects the new session.
     */
    public function enforceOnIssue(UserIdentityInterface $identity, string $jti, int $issuedAt, int $ttl): void
    {
        if ($this->policy === null) {
            return;
        }

        $max = $this->policy->getMaxSessions($identity);

        while ($this->store->getSessionCount($identity->id()) >= $max) {
            if ($this->policy->onLimitExceeded($identity) === SessionLimitAction::RejectNew) {
                throw new SessionLimitExceededException(
                    'Maximum concurrent sessions reached. Log out of another device to continue.'
                );
            }

            // InvalidateOldest: revoke oldest refresh token and remove its session entry
            $oldestJti = $this->store->removeOldestSession($identity->id());

            if ($oldestJti === null) {
                // Store is empty but count is still >= max (e.g. max=0).
                // Nothing left to evict — break to avoid an infinite loop.
                break;
            }

            $this->tokenStorage->revoke($oldestJti);
        }

        $this->store->addSession($identity->id(), $jti, $issuedAt, $ttl);
    }

    /**
     * Remove a single session entry — called on refresh token rotation.
     */
    public function removeSession(string $userId, string $jti): void
    {
        $this->store->removeSession($userId, $jti);
    }

    /**
     * Remove all session entries for a user — called on logout.
     */
    public function clearAllSessions(string $userId): void
    {
        $this->store->clearAll($userId);
    }
}
