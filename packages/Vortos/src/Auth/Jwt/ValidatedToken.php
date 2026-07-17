<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Result of a successful JWT access token validation.
 *
 * Carries the identity and the authz_version claim separately so the
 * authorization module can read the version without coupling to identity attributes.
 */
final readonly class ValidatedToken
{
    /**
     * @param string|null $sessionId The OIDC `sid` claim — the JTI of the refresh-token
     *                               session this access token belongs to. Lets middleware
     *                               check whether the session is still live (not revoked)
     *                               without decoding the refresh token. Null on legacy tokens
     *                               issued before the claim existed.
     */
    public function __construct(
        public UserIdentityInterface $identity,
        public int $authzVersion,
        public int $issuedAt = 0,
        public ?string $sessionId = null,
    ) {}
}
