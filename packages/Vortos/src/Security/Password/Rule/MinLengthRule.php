<?php

declare(strict_types=1);

namespace Vortos\Security\Password\Rule;

use Vortos\Security\Contract\PasswordRuleInterface;
use Vortos\Security\Password\PasswordPolicyViolation;

final class MinLengthRule implements PasswordRuleInterface
{
    public function __construct(private readonly int $minLength) {}

    public function validate(string $password): ?PasswordPolicyViolation
    {
        if (mb_strlen($password) < $this->minLength) {
            return new PasswordPolicyViolation(
                'min_length',
                "Password must be at least {$this->minLength} characters.",
            );
        }
        return null;
    }
}
