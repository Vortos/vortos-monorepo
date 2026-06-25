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
}
