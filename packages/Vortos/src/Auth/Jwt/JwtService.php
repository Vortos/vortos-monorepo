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

/**
 * Generates, validates, and refreshes JWT token pairs.
 *
 * Uses PHP's built-in hash_hmac for HMAC-SHA256 signing — no external JWT library
 * required. The implementation is intentional: external JWT libraries add complexity
 * for a straightforward HS256 use case. RS256 (asymmetric) can be added later if needed.
 *
 * ## Token format
 *
 * Standard JWT: header.payload.signature (base64url encoded, dot-separated)
 *
 * Access token payload:
 *   iss   — issuer (app name)
 *   sub   — user ID
 *   iat   — issued at (Unix timestamp)
 *   exp   — expires at (Unix timestamp)
 *   roles — user roles array
 *   type  — 'access'
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
 *   3. Revocation check (jti not in blacklist via TokenStorageInterface)
 *
 * Never accept tokens without all three checks passing.
 */
final class JwtService
{
    public function __construct(
        private JwtConfig $config,
        private TokenStorageInterface $tokenStorage,
    ) {}

    /**
     * Issue a new access + refresh token pair for the given user identity.
     *
     * Call this after successful credential verification.
     *
     * @throws \RuntimeException If token generation fails
     */
    public function issue(UserIdentityInterface $identity): JwtToken
    {
        $now = time();
        $accessExpiresAt = $now + $this->config->accessTokenTtl;
        $refreshExpiresAt = $now + $this->config->refreshTokenTtl;
        $jti = (string) new \Symfony\Component\Uid\UuidV7();

        $accessPayload = [
            'iss'   => $this->config->issuer,
            'sub'   => $identity->id(),
            'iat'   => $now,
            'exp'   => $accessExpiresAt,
            'roles' => $identity->roles(),
            'authz_version' => $identity->getAttribute('authz_version', 0),
            'type'  => 'access',
        ];

        $refreshPayload = [
            'iss'  => $this->config->issuer,
            'sub'  => $identity->id(),
            'iat'  => $now,
            'exp'  => $refreshExpiresAt,
            'jti'  => $jti,
            'type' => 'refresh',
        ];

        $accessToken = $this->encode($accessPayload);
        $refreshToken = $this->encode($refreshPayload);

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
     * @throws TokenInvalidException  If signature is invalid or format is malformed
     * @throws TokenExpiredException  If token has expired
     * @throws TokenRevokedException  If token has been revoked
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

        return new UserIdentity(
            id: $payload['sub'],
            roles: $payload['roles'] ?? [],
            attributes: $this->identityAttributes($payload),
        );
    }

    /**
     * Use a refresh token to issue a new access + refresh token pair.
     *
     * Validates the refresh token, revokes it, and issues a fresh pair.
     * This is token rotation — the old refresh token cannot be reused.
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

        $jti = $payload['jti'] ?? null;

        if ($jti === null || !$this->tokenStorage->isValid($jti)) {
            throw new TokenRevokedException('Refresh token has been revoked.');
        }

        $this->tokenStorage->revoke($jti);

        return $this->issue($identity);
    }

    /**
     * Revoke all refresh tokens for a user — used on logout.
     */
    public function revokeAll(string $userId): void
    {
        $this->tokenStorage->revokeAllForUser($userId);
    }

    /**
     * Extract the user ID from a refresh token without full validation.
     * Used by RefreshTokenController to load the user before calling refresh().
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

        return $payload['sub'];
    }

    /**
     * Encode a payload into a signed JWT string.
     */
    private function encode(array $payload): string
    {
        return JWT::encode($payload, $this->config->secret, 'HS256');
    }

    /**
     * Decode and verify a JWT string. Returns the payload array.
     *
     * @throws TokenInvalidException If format is wrong or signature is invalid
     */
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

    /**
     * Sign a string with HMAC-SHA256 using the configured secret.
     */
    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->config->secret, true),
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
