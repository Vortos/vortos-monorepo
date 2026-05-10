<?php

declare(strict_types=1);

namespace Vortos\Security\Password;

use Vortos\Security\Contract\PasswordRuleInterface;

/**
 * Validates passwords against all configured rules.
 *
 * Returns an empty list when the password passes all rules.
 * Returns a list of violations otherwise — each identifies which rule failed
 * and provides a user-facing message.
 *
 * Inject this service into your registration/password-change command handlers.
 *
 * Example:
 *
 *   $violations = $this->passwordPolicy->validate($command->password);
 *   if (!empty($violations)) {
 *       throw PasswordPolicyException::fromViolations($violations);
 *   }
 */
final class PasswordPolicyService
{
    /** @param list<PasswordRuleInterface> $rules */
    public function __construct(
        private readonly array $rules,
    ) {}

    /**
     * @return list<PasswordPolicyViolation> Empty list = password passes all rules.
     */
    public function validate(string $password): array
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            $violation = $rule->validate($password);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** Convenience method — returns true when the password passes all rules. */
    public function passes(string $password): bool
    {
        return empty($this->validate($password));
    }
}
