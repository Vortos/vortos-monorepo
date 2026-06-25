<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

use Firebase\JWT\JWT;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Exception\TokenExpiredException;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Exception\TokenReusedException;
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
 *   iss            — issuer (app/org name)
 *   aud            — audience (intended recipient service)
 *   sub            — user ID
 *   iat            — issued at (Unix timestamp)
 *   exp            — expires at (Unix timestamp)
 *   roles          — user roles array
 *   authz_version  — authorization cache version (framework-owned, passed explicitly to issue())
 *   type           — 'access'
 *   attrs          — app-defined claims from UserIdentityInterface::getClaims() (omitted when empty)
 *
 * Refresh token payload:
 *   iss   — issuer
 *   aud   — audience
 *   sub   — user ID
 *   iat   — issued at
 *   exp   — expires at
 *   jti   — unique token ID (UuidV7) — used for revocation tracking
 *   type  — 'refresh'
 *
 * ## Security
 *
 * Tokens are validated by:
 *   1. Signature verification against the configured Keyring. The token's `kid`
 *      header selects the verifying key, so tokens signed by a now-retiring key
 *      keep validating until they expire (zero-downtime key rotation).
 *   2. Expiry check (exp claim vs current time)
 *   3. Issuer check (iss claim vs configured issuer)
 *   4. Audience check (aud claim vs configured audience — prevents cross-service token confusion)
 *   5. Revocation check (jti not in blacklist via TokenStorageInterface)
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
     * @param int $authzVersion Current authorization cache version for the user.
     *                          Pass the value from AuthorizationVersionStoreInterface::versionForUser().
     *
     * @throws \Vortos\Auth\Session\Exception\SessionLimitExceededException If session limit is exceeded with RejectNew policy.
     */
    public function issue(UserIdentityInterface $identity, int $authzVersion = 0): JwtToken
    {
        $now = time();
        $accessExpiresAt = $now + $this->config->accessTokenTtl;
        $refreshExpiresAt = $now + $this->config->refreshTokenTtl;
        $jti = (string) new \Symfony\Component\Uid\UuidV7();

        $claims = $identity->getClaims();

        $accessPayload = [
            'iss'           => $this->config->issuer,
            'aud'           => $this->config->audience,
            'sub'           => $identity->id(),
            'iat'           => $now,
            'exp'           => $accessExpiresAt,
            'roles'         => $identity->roles(),
            'authz_version' => $authzVersion,
            'type'          => 'access',
        ];

        if ($claims !== []) {
            $accessPayload['attrs'] = $claims;
        }

        $refreshPayload = [
            'iss'  => $this->config->issuer,
            'aud'  => $this->config->audience,
            'sub'  => $identity->id(),
            'iat'  => $now,
            'exp'  => $refreshExpiresAt,
            'jti'  => $jti,
            'type' => 'refresh',
        ];

        $signingKey   = $this->config->keyring->activeSigningKey();
        $accessToken  = JWT::encode($accessPayload, $signingKey->signingMaterial(), $signingKey->algorithm, $signingKey->kid);
        $refreshToken = JWT::encode($refreshPayload, $signingKey->signingMaterial(), $signingKey->algorithm, $signingKey->kid);

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
     * Validate an access token and return a ValidatedToken containing the identity and authz version.
     *
     * @throws TokenInvalidException  If signature is invalid, format is malformed, or issuer is wrong
     * @throws TokenExpiredException  If token has expired
     */
    public function validate(string $token): ValidatedToken
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

        if (($payload['aud'] ?? '') !== $this->config->audience) {
            throw new TokenInvalidException('Token audience is invalid.');
        }

        return new ValidatedToken(
            identity: new UserIdentity(
                id: $payload['sub'],
                roles: $payload['roles'] ?? [],
                attributes: $this->identityAttributes($payload),
            ),
            authzVersion: (int) ($payload['authz_version'] ?? 0),
            issuedAt: (int) ($payload['iat'] ?? 0),
        );
    }

    /**
     * Use a refresh token to issue a new access + refresh token pair.
     *
     * Validates the refresh token, atomically consumes it, removes its session
     * entry, and issues a fresh pair. The old refresh token cannot be reused.
     *
     * If a validly-signed, non-expired refresh token has already been consumed,
     * this is treated as credential theft: all tokens for the user are revoked
     * and a TokenReusedException is thrown (RFC 6819 §5.2.2.3).
     *
     * @param int $authzVersion Current authorization cache version for the user.
     *
     * @throws TokenInvalidException  If refresh token is malformed
     * @throws TokenExpiredException  If refresh token has expired
     * @throws TokenReusedException   If refresh token was already consumed or revoked (breach detection)
     */
    public function refresh(string $refreshToken, UserIdentityInterface $identity, int $authzVersion = 0): JwtToken
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

        if (($payload['aud'] ?? '') !== $this->config->audience) {
            throw new TokenInvalidException('Token audience is invalid.');
        }

        $jti = $payload['jti'] ?? null;
        $sub = $payload['sub'];

        if ($jti === null) {
            throw new TokenInvalidException('Refresh token missing jti claim.');
        }

        $consumedUserId = $this->tokenStorage->consume($jti);

        if ($consumedUserId === null) {
            // Token was validly signed and not expired, but already consumed.
            // This is a refresh token reuse — treat as credential theft.
            $this->tokenStorage->revokeAllForUser($sub);
            $this->sessionEnforcer?->clearAllSessions($sub);
            throw new TokenReusedException(
                'Refresh token has already been used. All sessions for this user have been revoked as a security precaution.'
            );
        }

        $this->sessionEnforcer?->removeSession($sub, $jti);

        return $this->issue($identity, $authzVersion);
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

        if (($payload['aud'] ?? '') !== $this->config->audience) {
            throw new TokenInvalidException('Token audience is invalid.');
        }

        return $payload['sub'];
    }

    private function decode(string $token): array
    {
        // The full keyring is handed to JWT::decode; the token's `kid` header
        // selects the verifying key. Tokens with an unknown/missing kid fail.
        return (array) JWT::decode($token, $this->config->keyring->verificationKeys());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function identityAttributes(array $payload): array
    {
        return (array) ($payload['attrs'] ?? []);
    }
}
