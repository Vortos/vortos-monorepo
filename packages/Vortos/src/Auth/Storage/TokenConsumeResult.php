<?php

declare(strict_types=1);

namespace Vortos\Auth\Storage;

/**
 * Outcome of attempting to consume a refresh token JTI for rotation.
 *
 * Distinguishes the three cases a caller must handle differently. Historically
 * consume() returned `?string` (userId or null), which collapsed "deliberately
 * revoked" and "replayed/stolen" into the same null — forcing the refresh flow to
 * treat a user's own single-device sign-out as credential theft and revoke every
 * session the user had. That is the root cause of "revoke one device signs me out
 * everywhere". This result keeps the two apart:
 *
 *   - Rotated: the JTI was live (or within the rotation-grace window). Proceed to
 *     issue a fresh pair. `userId` is populated.
 *   - Revoked: a revocation tombstone was found — this session was deliberately
 *     signed out (single-device logout, admin revoke, or session-limit eviction).
 *     Reject THIS token only; do NOT touch the user's other sessions.
 *   - Reused: the JTI is validly signed and unexpired but is neither live nor
 *     tombstoned — it was already consumed and never deliberately revoked. This is
 *     the genuine refresh-token-reuse breach signal (RFC 6819 §5.2.2.3); the caller
 *     applies its theft policy (revoke all sessions for the user).
 */
final class TokenConsumeResult
{
    private function __construct(
        public readonly TokenConsumeStatus $status,
        public readonly ?string $userId,
    ) {}

    public static function rotated(string $userId): self
    {
        return new self(TokenConsumeStatus::Rotated, $userId);
    }

    public static function revoked(): self
    {
        return new self(TokenConsumeStatus::Revoked, null);
    }

    public static function reused(): self
    {
        return new self(TokenConsumeStatus::Reused, null);
    }

    public function isRotated(): bool
    {
        return $this->status === TokenConsumeStatus::Rotated;
    }

    public function isRevoked(): bool
    {
        return $this->status === TokenConsumeStatus::Revoked;
    }

    public function isReused(): bool
    {
        return $this->status === TokenConsumeStatus::Reused;
    }
}
