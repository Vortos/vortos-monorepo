<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

/**
 * Immutable JWT configuration.
 * Built by AuthExtension from VortosAuthConfig.
 */
final readonly class JwtConfig
{
    public function __construct(
        /**
         * HMAC-SHA256 signing secret.
         * Must be at least 32 characters. Use a cryptographically random value.
         * Generate: php -r "echo bin2hex(random_bytes(32));"
         */
        public string $secret,

        /**
         * Access token TTL in seconds. Default: 900 (15 minutes).
         * Short TTL reduces exposure window if a token is compromised.
         */
        public int $accessTokenTtl = 900,

        /**
         * Refresh token TTL in seconds. Default: 604800 (7 days).
         */
        public int $refreshTokenTtl = 604800,

        /**
         * Token issuer — included in 'iss' claim.
         * Use your application name or domain.
         */
        public string $issuer = 'vortos',
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
    }
}
