<?php

declare(strict_types=1);

namespace Vortos\Analytics\Privacy;

/**
 * Defense-in-depth over the allowlist: scans surviving values for email/phone/
 * credit-card-shaped strings and replaces them with a salted SHA-256 hash, unless
 * the key is explicitly marked "raw allowed". Pure and deterministic — the same
 * value always hashes to the same digest for a given salt, so equality checks
 * downstream (e.g. cohort joins) still work without ever exposing the raw value.
 *
 * A misconfigured allowlist still cannot leak raw PII: this is an independent gate,
 * not a fallback for the allowlist.
 */
final readonly class PiiRedactor
{
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';
    private const PHONE_PATTERN = '/^\+?[0-9][0-9\-.\s()]{6,18}[0-9]$/';
    private const CREDIT_CARD_PATTERN = '/^(?:\d[ -]?){13,19}$/';

    /** @param list<string> $rawAllowedKeys keys whose values bypass redaction entirely */
    public function __construct(
        private string $salt,
        private array $rawAllowedKeys = [],
    ) {}

    /**
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    public function redact(array $properties): array
    {
        $result = [];
        foreach ($properties as $key => $value) {
            if (in_array($key, $this->rawAllowedKeys, true)) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = $this->redactValue($value);
        }

        return $result;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->redactValue($v), $value);
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        if ($this->looksLikePii($value)) {
            return $this->hash($value);
        }

        return $value;
    }

    private function looksLikePii(string $value): bool
    {
        if (preg_match(self::EMAIL_PATTERN, $value) === 1) {
            return true;
        }

        $digitsOnly = preg_replace('/[^0-9]/', '', $value) ?? '';

        if (preg_match(self::PHONE_PATTERN, $value) === 1 && strlen($digitsOnly) >= 7) {
            return true;
        }

        if (preg_match(self::CREDIT_CARD_PATTERN, $value) === 1 && strlen($digitsOnly) >= 13 && strlen($digitsOnly) <= 19) {
            return true;
        }

        return false;
    }

    private function hash(string $value): string
    {
        return 'sha256:' . hash('sha256', $this->salt . $value);
    }
}
