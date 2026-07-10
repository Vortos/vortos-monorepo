<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin;

final readonly class AdminConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $prefix = '/admin/flags',
        public string $requiredRole = 'ROLE_ADMIN',
        /**
         * When true, an authenticated subject with the required role must ALSO have a
         * 2FA-verified session to reach the console. Enforced fail-closed: if no
         * {@see \Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface} is wired,
         * access is denied rather than silently allowed.
         */
        public bool $require2fa = false,
    ) {}
}
