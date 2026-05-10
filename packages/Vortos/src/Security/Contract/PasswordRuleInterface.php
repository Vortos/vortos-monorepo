<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

use Vortos\Security\Password\PasswordPolicyViolation;

interface PasswordRuleInterface
{
    /**
     * Validate the password against this rule.
     *
     * @return PasswordPolicyViolation|null Violation, or null if the password passes.
     */
    public function validate(string $password): ?PasswordPolicyViolation;
}
