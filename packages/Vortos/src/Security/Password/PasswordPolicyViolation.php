<?php

declare(strict_types=1);

namespace Vortos\Security\Password;

/**
 * A single password policy rule violation.
 *
 * PasswordPolicyService returns list<PasswordPolicyViolation>.
 * An empty list means the password passes all configured rules.
 */
final readonly class PasswordPolicyViolation
{
    public function __construct(
        /** Machine-readable rule code (e.g. 'min_length', 'require_digit'). */
        public string $rule,
        /** Human-readable message suitable for display in a form. */
        public string $message,
    ) {}
}
