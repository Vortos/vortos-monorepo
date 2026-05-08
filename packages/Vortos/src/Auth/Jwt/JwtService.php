<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Exception\TokenExpiredException;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Session\SessionEnforcer;

/**
 * Generates, validates, and refreshes JWT token pairs.
 *
 * ## Token format
 *
 * Standard JWT: header.payload.signature (base64url encoded, dot-separated)
 *
 * Access token payload:
 *   iss            — issuer (app name)
 *   sub            — user ID
 *   iat            — issued at (Unix timestamp)
 *   exp            — expires at (Unix timestamp)
 *   roles          — user roles array
 *   authz_version  — authorization cache version
 *   type           — 'access'
 *
 * Refresh token payload:
 *   iss   — issuer
 *   sub   — user ID
 *   iat   — issued at
 *   exp   — expires at
 *   jti   — unique token ID (UuidV7) — used for revocation tracking
 *   type  — 'refresh'
 *
 * ## Security
 *
 * Tokens are validated by:
 *   1. Signature verification (HMAC-SHA256 with secret key)
 *   2. Expiry check (exp claim vs current time)
 *   3. Issuer check (iss claim vs configured issuer)
 *   4. Revocation check (jti not in blacklist via TokenStorageInterface)
 *
 * ## Session limiting
 *
 * If SessionEnforcer is configured, issue() enforces concurrent session limits
 * before storing the new refresh token. May throw SessionLimitExceededException
 * if the policy rejects the new session (RejectNew action).
 */
final class JwtService
{
    public function __construct(
        private JwtConfig $config,
        private TokenStorageInterface $tokenStorage,
        private ?SessionEnforcer $sessionEnforcer = null,
    ) {}

    /**
     * Issue a new access + refresh token pair for the given user identity.
     *
     * Call this after successful credential verification.
     *
     * @throws \Vortos\Auth\Session\Exception\SessionLimitExceededException If session limit is exceeded with RejectNew policy.
     */
    public function issue(UserIdentityInterface $identity): JwtToken
    {
        $now = time();
        $accessExpiresAt = $now + $this->config->accessTokenTtl;
        $refreshExpiresAt = $now + $this->config->refreshTokenTtl;
        $jti = (string) new \Symfony\Component\Uid\UuidV7();

        $accessPayload = [
            'iss'           => $this->config->issuer,
            'sub'           => $identity->id(),
            'iat'           => $now,
            'exp'           => $accessExpiresAt,
            'roles'         => $identity->roles(),
            'authz_version' => $identity->getAttribute('authz_version', 0),
            'type'          => 'access',
        ];

        $refreshPayload = [
            'iss'  => $this->config->issuer,
            'sub'  => $identity->id(),
            'iat'  => $now,
            'exp'  => $refreshExpiresAt,
            'jti'  => $jti,
            'type' => 'refresh',
        ];

        $accessToken = JWT::encode($accessPayload, $this->config->secret, 'HS256');
        $refreshToken = JWT::encode($refreshPayload, $this->config->secret, 'HS256');

        // Session enforcement — may throw SessionLimitExceededException
        $this->sessionEnforcer?->enforceOnIssue($identity, $jti, $now, $this->config->refreshTokenTtl);

        // Store refresh token JTI so it can be revoked later
        $this->tokenStorage->store($jti, $identity->id(), $refreshExpiresAt);

        return new JwtToken(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            accessTokenExpiresAt: $accessExpiresAt,
            refreshTokenExpiresAt: $refreshExpiresAt,
        );
    }

    /**
     * Validate an access token and return the user identity from its claims.
     *
     * @throws TokenInvalidException  If signature is invalid, format is malformed, or issuer is wrong
     * @throws TokenExpiredException  If token has expired
     */
    public function validate(string $token): UserIdentityInterface
    {
        try {
            $payload = $this->decode($token);
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new TokenExpiredException('Access token has expired.', 0, $e);
        } catch (\Throwable $e) {
            throw new TokenInvalidException($e->getMessage(), 0, $e);
        }

        if ($payload['type'] !== 'access') {
            throw new TokenInvalidException('Token is not an access token.');
        }

        if (($payload['iss'] ?? '') !== $this->config->issuer) {
            throw new TokenInvalidException('Token issuer is invalid.');
        }

        return new UserIdentity(
            id: $payload['sub'],
            roles: $payload['roles'] ?? [],
            attributes: $this->identityAttributes($payload),
        );
    }

    /**
     * Use a refresh token to issue a new access + refresh token pair.
     *
     * Validates the refresh token, revokes it, removes its session entry,
     * and issues a fresh pair. The old refresh token cannot be reused.
     *
     * @throws TokenInvalidException  If refresh token is malformed
     * @throws TokenExpiredException  If refresh token has expired
     * @throws TokenRevokedException  If refresh token was already used or revoked
     */
    public function refresh(string $refreshToken, UserIdentityInterface $identity): JwtToken
    {
        try {
            $payload = $this->decode($refreshToken);
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new TokenExpiredException('Refresh token has expired.', 0, $e);
        } catch (\Throwable $e) {
            throw new TokenInvalidException($e->getMessage(), 0, $e);
        }

        if ($payload['type'] !== 'refresh') {
            throw new TokenInvalidException('Token is not a refresh token.');
        }

        if (($payload['iss'] ?? '') !== $this->config->issuer) {
            throw new TokenInvalidException('Token issuer is invalid.');
        }

        $jti = $payload['jti'] ?? null;

        if ($jti === null || !$this->tokenStorage->isValid($jti)) {
            throw new TokenRevokedException('Refresh token has been revoked.');
        }

        $this->tokenStorage->revoke($jti);
        $this->sessionEnforcer?->removeSession($payload['sub'], $jti);

        return $this->issue($identity);
    }

    /**
     * Revoke all refresh tokens for a user — used on logout.
     */
    public function revokeAll(string $userId): void
    {
        $this->tokenStorage->revokeAllForUser($userId);
        $this->sessionEnforcer?->clearAllSessions($userId);
    }

    /**
     * Extract the user ID from a refresh token without full validation.
     * Used by RefreshTokenController to load the user before calling refresh().
     *
     * Does NOT check revocation — call refresh() for full validation.
     *
     * @throws TokenInvalidException If token is malformed
     * @throws TokenExpiredException If token has expired
     */
    public function getUserIdFromRefreshToken(string $token): string
    {
        try {
            $payload = $this->decode($token);
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new TokenExpiredException('Refresh token has expired.', 0, $e);
        } catch (\Throwable $e) {
            throw new TokenInvalidException($e->getMessage(), 0, $e);
        }

        if ($payload['type'] !== 'refresh') {
            throw new TokenInvalidException('Token is not a refresh token.');
        }

        if (($payload['iss'] ?? '') !== $this->config->issuer) {
            throw new TokenInvalidException('Token issuer is invalid.');
        }

        return $payload['sub'];
    }

    private function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->config->secret, 'HS256'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function identityAttributes(array $payload): array
    {
        unset(
            $payload['iss'],
            $payload['sub'],
            $payload['iat'],
            $payload['exp'],
            $payload['roles'],
            $payload['type'],
        );

        return $payload;
    }
}
