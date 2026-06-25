<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

use Vortos\Auth\Jwt\Key\Keyring;

/**
 * Immutable JWT configuration.
 * Built by AuthExtension from VortosAuthConfig.
 *
 * Signing material lives in the {@see Keyring}, which carries one or more
 * kid-addressed keys for zero-downtime rotation. See {@see JwtService} for how
 * the active key signs and the whole ring verifies.
 */
final readonly class JwtConfig
{
    public function __construct(
        /**
         * The signing keyring. Tokens are signed by the ring's Active key and
         * verified against every key in the ring (by `kid`).
         */
        public Keyring $keyring,

        /**
         * Access token TTL in seconds. Default: 900 (15 minutes).
         */
        public int $accessTokenTtl = 900,

        /**
         * Refresh token TTL in seconds. Default: 604800 (7 days).
         */
        public int $refreshTokenTtl = 604800,

        /**
         * Token issuer — included in 'iss' claim. Must be explicitly set.
         */
        public string $issuer = 'vortos',

        /**
         * Token audience — included in 'aud' claim and validated on every token.
         * Prevents cross-service token confusion when services share a keyring.
         */
        public string $audience = 'vortos',
    ) {}

    /**
     * Convenience constructor for the common single-secret HS256 case (tests, single-service apps).
     */
    public static function fromSecret(
        string $secret,
        int $accessTokenTtl = 900,
        int $refreshTokenTtl = 604800,
        string $issuer = 'vortos',
        string $audience = 'vortos',
    ): self {
        return new self(Keyring::fromSecret($secret), $accessTokenTtl, $refreshTokenTtl, $issuer, $audience);
    }
}
