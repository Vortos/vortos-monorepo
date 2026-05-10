<?php

declare(strict_types=1);

namespace Vortos\Security\Password\Rule;

use Vortos\Security\Contract\PasswordRuleInterface;
use Vortos\Security\Password\PasswordPolicyViolation;

/**
 * Rejects passwords that appear in the top-10k most common password list.
 *
 * The list is loaded once at construction time and stored as a hash set
 * for O(1) lookups. Case-insensitive comparison.
 */
final class CommonPasswordRule implements PasswordRuleInterface
{
    /** @var array<string, true> */
    private array $common;

    public function __construct()
    {
        $this->common = $this->loadCommonPasswords();
    }

    public function validate(string $password): ?PasswordPolicyViolation
    {
        if (isset($this->common[strtolower($password)])) {
            return new PasswordPolicyViolation(
                'common_password',
                'This password is too common. Please choose a more unique password.',
            );
        }
        return null;
    }

    private function loadCommonPasswords(): array
    {
        $file = __DIR__ . '/../../../Resources/common-passwords.txt';

        if (!file_exists($file)) {
            return [];
        }

        $result = [];
        $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $result[strtolower(trim($line))] = true;
        }

        return $result;
    }
}
