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
    public function __construct(
        public UserIdentityInterface $identity,
        public int $authzVersion,
        public int $issuedAt = 0,
    ) {}
}
