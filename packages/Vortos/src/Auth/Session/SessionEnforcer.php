<?php
declare(strict_types=1);

namespace Vortos\Auth\Session;

use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Session\Contract\SessionPolicyInterface;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\Exception\SessionLimitExceededException;

final class SessionEnforcer
{
    public function __construct(
        private SessionStoreInterface $store,
        private TokenStorageInterface $tokenStorage,
        private ?SessionPolicyInterface $policy,
    ) {}

    /**
     * Atomically enforce session limits and add the new session.
     *
     * @param array<string, mixed> $meta Per-session metadata (device / IP / original
     *                                   logged-in-at) stored with the session and carried
     *                                   across refresh-token rotation.
     *
     * @throws SessionLimitExceededException If the policy rejects the new session.
     */
    public function enforceOnIssue(UserIdentityInterface $identity, string $jti, int $issuedAt, int $ttl, array $meta = []): void
    {
        if ($this->policy === null) {
            return;
        }

        $max = $this->policy->getMaxSessions($identity);
        $evictOldest = $this->policy->onLimitExceeded($identity) === SessionLimitAction::InvalidateOldest;

        $result = $this->store->enforceAndAdd(
            $identity->id(),
            $jti,
            $issuedAt,
            $ttl,
            $max,
            $evictOldest,
            $meta,
        );

        if ($result->rejected) {
            throw new SessionLimitExceededException(
                'Maximum concurrent sessions reached. Log out of another device to continue.',
            );
        }

        foreach ($result->evictedJtis as $evictedJti) {
            $this->tokenStorage->revoke($evictedJti);
        }
    }

    public function removeSession(string $userId, string $jti): void
    {
        $this->store->removeSession($userId, $jti);
    }

    /**
     * Metadata stored for a session — used to carry device metadata across rotation.
     *
     * @return array<string, mixed>
     */
    public function getSessionMeta(string $userId, string $jti): array
    {
        return $this->store->getSessionMeta($userId, $jti);
    }

    public function clearAllSessions(string $userId): void
    {
        $this->store->clearAll($userId);
    }
}
