<?php

declare(strict_types=1);

namespace Vortos\Auth\Contract;

use Vortos\Auth\Storage\TokenConsumeResult;

/**
 * Stores and validates refresh token JTIs for revocation tracking.
 *
 * Refresh tokens are tracked by their JTI (JWT ID) — a unique identifier
 * embedded in the token payload. On refresh or logout, the JTI is revoked.
 * On validation, the JTI is checked against the store.
 *
 * ## Why track refresh tokens but not access tokens
 *
 * Access tokens are short-lived (15 minutes). Revoking them would require
 * a database/cache lookup on every API request — too expensive. The short TTL
 * is the revocation mechanism for access tokens.
 *
 * Refresh tokens are long-lived (7 days). Without tracking, a stolen refresh
 * token would be valid for 7 days even after the user logs out. Tracking JTIs
 * in Redis (with matching TTL) solves this at negligible cost — refresh is
 * infrequent (once per 15 minutes maximum).
 *
 * Implementations:
 *   RedisTokenStorage     — production, TTL-based, automatic expiry
 *   InMemoryTokenStorage  — testing
 */
interface TokenStorageInterface
{
    /**
     * Store a refresh token JTI as valid.
     *
     * @param string $jti       Unique token ID from JWT 'jti' claim
     * @param string $userId    Associated user ID
     * @param int    $expiresAt Unix timestamp when this token expires
     */
    public function store(string $jti, string $userId, int $expiresAt): void;

    /**
     * Atomically consume a JTI for refresh-token rotation.
     *
     * Only one concurrent caller can rotate a given JTI; the operation is a single
     * atomic GET+DEL+SREM. The result distinguishes three cases the caller must
     * handle differently (see {@see TokenConsumeResult}):
     *
     *   - Rotated: JTI was live (or within the rotation-grace window) → issue a
     *     fresh pair.
     *   - Revoked: a revocation tombstone was found → this session was deliberately
     *     signed out; reject this token only, leave the user's other sessions alone.
     *   - Reused: JTI was neither live nor tombstoned → genuine refresh-token reuse
     *     (RFC 6819 §5.2.2.3); the caller revokes all of the user's tokens.
     *
     * Distinguishing Revoked from Reused is what prevents a deliberate single-device
     * sign-out from being misread as theft and logging the user out everywhere.
     */
    public function consume(string $jti): TokenConsumeResult;

    /**
     * Revoke a specific JTI — used on single-device logout, admin session revoke,
     * and session-limit eviction.
     *
     * Leaves a revocation tombstone so a later, benign presentation of the same JTI
     * (the revoked device firing its own refresh before it notices) is reported as
     * {@see TokenConsumeStatus::Revoked}, not misclassified as reuse/theft.
     */
    public function revoke(string $jti): void;

    /**
     * Revoke all refresh tokens for a user — used on logout-all-devices
     * and as a breach response when refresh token reuse is detected.
     *
     * Implementations should track user → JTIs mapping for this to work efficiently.
     */
    public function revokeAllForUser(string $userId): void;
}
