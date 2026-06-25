<?php

declare(strict_types=1);

namespace Vortos\Secrets\Value;

use InvalidArgumentException;

/**
 * A validated secret name.
 *
 * Lower-snake-ish, path/env friendly: `^[a-z][a-z0-9_.-]*$`. Rejects empty,
 * whitespace, and any `..` segment (would otherwise let a key escape a directory
 * scope in a file-backed store).
 */
final readonly class SecretKey
{
    private const PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('Secret key must be a non-empty string with no leading/trailing whitespace.');
        }

        if (str_contains($value, '..')) {
            throw new InvalidArgumentException("Secret key '{$value}' must not contain '..'.");
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Secret key '{$value}' is invalid. Must match ^[a-z][a-z0-9_.-]*$.",
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** UPPER_SNAKE form, suitable for an environment variable name. */
    public function toEnvVar(): string
    {
        return strtoupper(str_replace(['.', '-'], '_', $this->value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
