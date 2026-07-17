<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Contract;

use Vortos\Auth\Session\SessionEnforcementResult;

interface SessionStoreInterface
{
    /**
     * @param array<string, mixed> $meta Per-session metadata (user agent, IP, original
     *                                   logged-in-at, …) stored alongside the session and
     *                                   surfaced by {@see listSessionsWithMeta()}. Best-effort
     *                                   display data — implementations may drop it silently.
     */
    public function enforceAndAdd(
        string $userId,
        string $jti,
        int $issuedAt,
        int $ttl,
        int $maxSessions,
        bool $evictOldest,
        array $meta = [],
    ): SessionEnforcementResult;

    /**
     * @param array<string, mixed> $meta Per-session metadata (see {@see enforceAndAdd()}).
     */
    public function addSession(string $userId, string $jti, int $issuedAt, int $ttl, array $meta = []): void;

    /** Remove a session and any metadata stored for it. */
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

    /**
     * List a user's active sessions with their metadata — the one-shot query behind a
     * device-management UI, so callers don't maintain a parallel metadata side-store.
     *
     * @return array<string, array{issued_at: int, meta: array<string, mixed>}> jti => details
     */
    public function listSessionsWithMeta(string $userId): array;

    /**
     * Metadata stored for a single session, or [] if none. Used to carry a session's
     * device metadata across refresh-token rotation.
     *
     * @return array<string, mixed>
     */
    public function getSessionMeta(string $userId, string $jti): array;

    /** True when the user currently has an active session with this JTI. */
    public function hasSession(string $userId, string $jti): bool;
}
