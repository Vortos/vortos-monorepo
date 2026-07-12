<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Contract;

use Vortos\Auth\Session\SessionEnforcementResult;

interface SessionStoreInterface
{
    public function enforceAndAdd(
        string $userId,
        string $jti,
        int $issuedAt,
        int $ttl,
        int $maxSessions,
        bool $evictOldest,
    ): SessionEnforcementResult;

    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl): void;
    public function removeSession(string $userId, string $jti): void;
    public function getSessionCount(string $userId): int;
    public function clearAll(string $userId): void;

    /**
     * List a user's active session JTIs with their issued-at timestamps, newest first is
     * left to the caller — the map is unordered. Backs session/device-management UIs.
     *
     * @return array<string, int> jti => issuedAt (unix seconds)
     */
    public function listSessions(string $userId): array;
}
