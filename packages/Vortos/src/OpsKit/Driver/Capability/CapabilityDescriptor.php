<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

use InvalidArgumentException;

/**
 * What a driver declares it can do — the single source of truth for config-time
 * validation.
 *
 * A descriptor is a set of enum-keyed booleans (does this driver support
 * capability X?) plus free-form scalar constraints (e.g. `max_nodes => 1`,
 * `cert_ttl_seconds => 300`). It is:
 *
 *  - **Versioned** — {@see $schemaVersion} lets a future descriptor shape evolve
 *    without silently re-classifying old ones.
 *  - **Machine-diffable** — {@see satisfies()} returns a structured
 *    {@see CapabilityMismatch} (not a bare bool) so doctor can print exactly what
 *    is missing.
 *  - **Canonically serializable** — {@see toArray()} sorts keys so the serialized
 *    form is deterministic; this is what makes a pinned TCK vector meaningful and
 *    lets two descriptors be compared byte-for-byte.
 *
 * Readonly: a driver's capabilities never change at runtime.
 */
final readonly class CapabilityDescriptor
{
    /**
     * @param array<string, bool>             $capabilities Keyed by capability key → supported
     * @param array<string, int|float|string|bool> $constraints  Free-form scalar constraints
     */
    private function __construct(
        public array $capabilities,
        public array $constraints,
        public int $schemaVersion,
    ) {}

    /**
     * Public factory over untrusted input (a driver hand-writes its descriptor), so
     * values are deliberately `mixed` and validated here.
     *
     * @param array<CapabilityKey|string, mixed> $capabilities
     * @param array<array-key, mixed>            $constraints
     */
    public static function create(array $capabilities, array $constraints = [], int $schemaVersion = 1): self
    {
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Capability descriptor schemaVersion must be >= 1.');
        }

        $normalized = [];
        foreach ($capabilities as $key => $supported) {
            $name = self::normalizeKey($key);
            if (!is_bool($supported)) {
                throw new InvalidArgumentException(
                    "Capability '{$name}' must map to a bool, got " . get_debug_type($supported) . '.'
                );
            }
            if (array_key_exists($name, $normalized)) {
                throw new InvalidArgumentException("Duplicate capability key '{$name}'.");
            }
            $normalized[$name] = $supported;
        }

        foreach ($constraints as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Constraint names must be non-empty strings.');
            }
            if (!is_scalar($value)) {
                throw new InvalidArgumentException(
                    "Constraint '{$name}' must be a scalar, got " . get_debug_type($value) . '.'
                );
            }
        }

        return new self($normalized, $constraints, $schemaVersion);
    }

    public function supports(CapabilityKey|string $key): bool
    {
        return $this->capabilities[self::normalizeKey($key)] ?? false;
    }

    /** Returns the declared constraint value, or null when not declared. */
    public function constraint(string $name): int|float|string|bool|null
    {
        return $this->constraints[$name] ?? null;
    }

    /**
     * Diff this descriptor against what a selection requires.
     *
     * Returns null when every requirement is met; otherwise a structured mismatch
     * describing precisely which capabilities are missing and which constraints are
     * violated — the input to a clear, actionable config-time failure.
     */
    public function satisfies(RequiredCapabilities $required): ?CapabilityMismatch
    {
        $missing = [];
        foreach ($required->capabilities as $name) {
            if (!$this->supports($name)) {
                $missing[] = $name;
            }
        }

        $violations = [];
        foreach ($required->constraints as $name => $expected) {
            $actual = $this->constraint($name);
            if ($actual !== $expected) {
                $violations[$name] = ['required' => $expected, 'actual' => $actual];
            }
        }

        if ($missing === [] && $violations === []) {
            return null;
        }

        return new CapabilityMismatch($missing, $violations);
    }

    /**
     * Canonical, deterministic serialization (keys sorted) for stable diffing.
     *
     * @return array{schemaVersion:int, capabilities:array<string,bool>, constraints:array<string,int|float|string|bool>}
     */
    public function toArray(): array
    {
        $capabilities = $this->capabilities;
        ksort($capabilities);
        $constraints = $this->constraints;
        ksort($constraints);

        return [
            'schemaVersion' => $this->schemaVersion,
            'capabilities' => $capabilities,
            'constraints' => $constraints,
        ];
    }

    /**
     * @param array{schemaVersion?:int, capabilities?:array<string,bool>, constraints?:array<string,int|float|string|bool>} $data
     */
    public static function fromArray(array $data): self
    {
        return self::create(
            $data['capabilities'] ?? [],
            $data['constraints'] ?? [],
            $data['schemaVersion'] ?? 1,
        );
    }

    private static function normalizeKey(CapabilityKey|string $key): string
    {
        $name = $key instanceof CapabilityKey ? $key->key() : $key;
        if ($name === '') {
            throw new InvalidArgumentException('Capability key must be a non-empty string.');
        }

        return $name;
    }
}
