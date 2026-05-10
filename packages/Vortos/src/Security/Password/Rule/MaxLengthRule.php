<?php

declare(strict_types=1);

namespace Vortos\Security\Password\Rule;

use Vortos\Security\Contract\PasswordRuleInterface;
use Vortos\Security\Password\PasswordPolicyViolation;

final class MaxLengthRule implements PasswordRuleInterface
{
    public function __construct(private readonly int $maxLength) {}

    public function validate(string $password): ?PasswordPolicyViolation
    {
        if (mb_strlen($password) > $this->maxLength) {
            return new PasswordPolicyViolation(
                'max_length',
                "Password must not exceed {$this->maxLength} characters.",
            );
        }
        return null;
    }
}
