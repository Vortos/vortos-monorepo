<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

use InvalidArgumentException;

/**
 * What a selection (a strategy, a target, a `config/deploy.php` choice) requires of
 * the driver it selects.
 *
 * Diffed against a driver's {@see CapabilityDescriptor} at config-validation time
 * (Block 6 onwards) via {@see CapabilityDescriptor::satisfies()}. Selecting
 * `rolling` on a single-VPS target that declares `rolling_across_nodes=false` then
 * fails in `config/deploy.php` validation — not at 3am.
 *
 * Immutable; build with the fluent {@see require()} / {@see withConstraint()}.
 */
final readonly class RequiredCapabilities
{
    /**
     * @param list<string>                          $capabilities Capability keys that must be supported
     * @param array<string, int|float|string|bool>  $constraints  Constraint values that must match exactly
     */
    private function __construct(
        public array $capabilities,
        public array $constraints,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param array<CapabilityKey|string>          $capabilities
     * @param array<string, int|float|string|bool> $constraints
     */
    public static function of(array $capabilities, array $constraints = []): self
    {
        $required = self::none();
        foreach ($capabilities as $capability) {
            $required = $required->require($capability);
        }
        foreach ($constraints as $name => $value) {
            $required = $required->withConstraint($name, $value);
        }

        return $required;
    }

    public function require(CapabilityKey|string $key): self
    {
        $name = $key instanceof CapabilityKey ? $key->key() : $key;
        if ($name === '') {
            throw new InvalidArgumentException('Required capability key must be a non-empty string.');
        }

        if (in_array($name, $this->capabilities, true)) {
            return $this;
        }

        return new self([...$this->capabilities, $name], $this->constraints);
    }

    public function withConstraint(string $name, int|float|string|bool $value): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('Constraint name must be a non-empty string.');
        }

        return new self($this->capabilities, [...$this->constraints, $name => $value]);
    }
}
