<?php

declare(strict_types=1);

namespace Vortos\Auth\Contract;

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
     * Atomically consume a JTI — returns the associated userId if the JTI was
     * valid, null if already consumed, expired, or never stored.
     *
     * Only one concurrent caller succeeds; all subsequent callers get null.
     * This is the primary mechanism for refresh token rotation: the caller that
     * gets null on a validly-signed, non-expired token should treat it as
     * credential theft and revoke all tokens for the user (RFC 6819 §5.2.2.3).
     */
    public function consume(string $jti): ?string;

    /**
     * Revoke a specific JTI — used on single-device logout.
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
