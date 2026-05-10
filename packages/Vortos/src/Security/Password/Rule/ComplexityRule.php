<?php

declare(strict_types=1);

namespace Vortos\Security\Password\Rule;

use Vortos\Security\Contract\PasswordRuleInterface;
use Vortos\Security\Password\PasswordPolicyViolation;

final class ComplexityRule implements PasswordRuleInterface
{
    public function __construct(
        private readonly bool $requireUppercase,
        private readonly bool $requireLowercase,
        private readonly bool $requireDigit,
        private readonly bool $requireSpecial,
    ) {}

    public function validate(string $password): ?PasswordPolicyViolation
    {
        $missing = [];

        if ($this->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $missing[] = 'an uppercase letter';
        }

        if ($this->requireLowercase && !preg_match('/[a-z]/', $password)) {
            $missing[] = 'a lowercase letter';
        }

        if ($this->requireDigit && !preg_match('/[0-9]/', $password)) {
            $missing[] = 'a digit';
        }

        if ($this->requireSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $missing[] = 'a special character';
        }

        if (empty($missing)) {
            return null;
        }

        return new PasswordPolicyViolation(
            'complexity',
            'Password must contain ' . implode(', ', $missing) . '.',
        );
    }
}
