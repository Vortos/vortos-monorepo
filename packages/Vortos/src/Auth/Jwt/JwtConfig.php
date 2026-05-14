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
         * Signing algorithm — 'HS256' or 'RS256'.
         * HS256: single shared secret (simple, good for single-service apps).
         * RS256: asymmetric key pair (better for multi-service — verifiers only need the public key).
         */
        public string $algorithm = 'HS256',

        /**
         * HMAC-SHA256 signing secret (HS256 only).
         * Must be at least 64 hex characters. Generate: bin2hex(random_bytes(32))
         */
        public string $secret = '',

        /**
         * RSA private key PEM string (RS256 only). Used to sign tokens.
         * Load from a file outside the project root — never commit PEM files to git.
         */
        public string $privateKey = '',

        /**
         * RSA public key PEM string (RS256 only). Used to verify tokens.
         */
        public string $publicKey = '',

        /**
         * Access token TTL in seconds. Default: 900 (15 minutes).
         */
        public int $accessTokenTtl = 900,

        /**
         * Refresh token TTL in seconds. Default: 604800 (7 days).
         */
        public int $refreshTokenTtl = 604800,

        /**
         * Token issuer — included in 'iss' claim.
         */
        public string $issuer = 'vortos',
    ) {
        match ($algorithm) {
            'HS256' => $this->validateHs256($secret),
            'RS256' => $this->validateRs256($privateKey, $publicKey),
            default => throw new \InvalidArgumentException(
                "Unsupported JWT algorithm '{$algorithm}'. Supported: HS256, RS256."
            ),
        };
    }

    private function validateHs256(string $secret): void
    {
        if (strlen($secret) < 64) {
            throw new \InvalidArgumentException(
                'JWT secret must be at least 64 characters for HS256. Generate one with: bin2hex(random_bytes(32))'
            );
        }
    }

    private function validateRs256(string $privateKey, string $publicKey): void
    {
        if ($privateKey === '' || $publicKey === '') {
            throw new \InvalidArgumentException(
                'RS256 requires both a private key and a public key. ' .
                'Use ->privateKeyPath() or ->privateKey() / ->publicKeyPath() or ->publicKey() in your auth config.'
            );
        }
    }
}
