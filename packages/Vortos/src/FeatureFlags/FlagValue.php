<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Vortos\FeatureFlags\Exception\InvalidFlagValueException;

/**
 * A typed, immutable flag value (`bool | string | number | json`).
 *
 * Constructed through the named factories so the runtime value can never drift
 * from its declared {@see FlagValueType}. JSON payloads are size- and depth-bounded
 * at construction (defence against oversized/maliciously-nested config — PLATFORM §6).
 *
 * Storage round-trips losslessly through {@see encode()}/{@see decode()}; the engine↔SDK
 * wire contract treats the canonical JSON encoding as authoritative (see WIRE_CONTRACT.md).
 */
final class FlagValue
{
    /** Hard ceiling on a serialized JSON value. Rejected at construction. */
    public const MAX_JSON_BYTES = 32_768;

    /** Hard ceiling on JSON nesting depth. Rejected at construction. */
    public const MAX_JSON_DEPTH = 32;

    private function __construct(
        public readonly FlagValueType $type,
        public readonly bool|string|float|array|null $value,
    ) {}

    public static function bool(bool $value): self
    {
        return new self(FlagValueType::Bool, $value);
    }

    public static function string(string $value): self
    {
        return new self(FlagValueType::String, $value);
    }

    public static function number(int|float $value): self
    {
        return new self(FlagValueType::Number, (float) $value);
    }

    /**
     * @param array<array-key,mixed>|null $value
     */
    public static function json(?array $value): self
    {
        if ($value !== null) {
            self::assertJsonWithinBounds($value);
        }

        return new self(FlagValueType::Json, $value);
    }

    /** The safe zero/fallback for a type (false / '' / 0.0 / null). */
    public static function zero(FlagValueType $type): self
    {
        return new self($type, $type->zeroValue());
    }

    /**
     * Coerce an arbitrary PHP value into a typed FlagValue. Used at the storage and
     * admin-input boundary. Throws on a value that cannot represent the target type
     * so corrupt data never silently becomes a wrong treatment.
     */
    public static function of(FlagValueType $type, mixed $value): self
    {
        if ($value === null) {
            return self::zero($type);
        }

        return match ($type) {
            FlagValueType::Bool   => self::bool(self::toBool($value)),
            FlagValueType::String => self::string(self::toString($value)),
            FlagValueType::Number => self::number(self::toNumber($value)),
            FlagValueType::Json   => self::json(self::toJsonArray($value)),
        };
    }

    public function asBool(): bool
    {
        return self::toBool($this->value);
    }

    public function asString(): string
    {
        return self::toString($this->value);
    }

    public function asNumber(): float
    {
        return self::toNumber($this->value);
    }

    /** @return array<array-key,mixed>|null */
    public function asJson(): ?array
    {
        return is_array($this->value) ? $this->value : null;
    }

    /** The raw, type-correct PHP value (for serialization into the wire response). */
    public function raw(): bool|string|float|array|null
    {
        return $this->value;
    }

    /**
     * Canonical storage encoding: a JSON string, or null for a null JSON value.
     * Stable for a given value so config hashes (the wire `version`) are deterministic.
     */
    public function encode(): ?string
    {
        if ($this->type === FlagValueType::Json && $this->value === null) {
            return null;
        }

        return json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Inverse of {@see encode()}. A null/absent column hydrates to the type zero. */
    public static function decode(FlagValueType $type, ?string $encoded): self
    {
        if ($encoded === null || $encoded === '') {
            return self::zero($type);
        }

        try {
            $decoded = json_decode($encoded, true, self::MAX_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidFlagValueException(
                sprintf('Corrupt %s flag value in storage: %s', $type->value, $e->getMessage()),
                previous: $e,
            );
        }

        return self::of($type, $decoded);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Accept the common storage encodings of booleans without surprises.
        return match ($value) {
            1, '1', 'true', 'on', 'yes'  => true,
            0, '0', 'false', 'off', 'no', '' => false,
            default => (bool) $value,
        };
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidFlagValueException('Value is not coercible to string.');
    }

    private static function toNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new InvalidFlagValueException('Value is not coercible to number.');
    }

    /** @return array<array-key,mixed> */
    private static function toJsonArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidFlagValueException('JSON flag value must be an array/object.');
        }

        self::assertJsonWithinBounds($value);

        return $value;
    }

    /** @param array<array-key,mixed> $value */
    private static function assertJsonWithinBounds(array $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            throw new InvalidFlagValueException(
                sprintf('JSON flag value exceeds %d bytes.', self::MAX_JSON_BYTES),
            );
        }

        if (self::depth($value) > self::MAX_JSON_DEPTH) {
            throw new InvalidFlagValueException(
                sprintf('JSON flag value exceeds max nesting depth of %d.', self::MAX_JSON_DEPTH),
            );
        }
    }

    private static function depth(mixed $value, int $current = 1): int
    {
        if (!is_array($value) || $value === []) {
            return $current;
        }

        $max = $current;
        foreach ($value as $child) {
            if (is_array($child)) {
                $max = max($max, self::depth($child, $current + 1));
            }
        }

        return $max;
    }
}
