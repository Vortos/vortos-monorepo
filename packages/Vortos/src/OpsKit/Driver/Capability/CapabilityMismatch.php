<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

/**
 * The structured result of a failed capability check — what is missing, precisely.
 *
 * Never a bare boolean: a mismatch carries the exact missing capabilities and the
 * exact constraint violations so {@see message()} can print an actionable failure
 * ("driver 'ssh-compose' for concern 'deploy' is missing capability
 * 'rolling_across_nodes'") rather than "capability check failed".
 */
final readonly class CapabilityMismatch
{
    /**
     * @param list<string>                                                          $missingCapabilities
     * @param array<string, array{required:int|float|string|bool, actual:int|float|string|bool|null}> $constraintViolations
     */
    public function __construct(
        public array $missingCapabilities,
        public array $constraintViolations,
    ) {}

    public function isEmpty(): bool
    {
        return $this->missingCapabilities === [] && $this->constraintViolations === [];
    }

    /** Human-readable, actionable description for config-time failures and doctor output. */
    public function message(string $driverKey, string $concern): string
    {
        $parts = [];

        foreach ($this->missingCapabilities as $capability) {
            $parts[] = "missing capability '{$capability}'";
        }

        foreach ($this->constraintViolations as $name => $violation) {
            $parts[] = sprintf(
                "constraint '%s' must be %s but driver declares %s",
                $name,
                self::render($violation['required']),
                self::render($violation['actual']),
            );
        }

        return sprintf(
            "Driver '%s' for concern '%s' does not satisfy the required capabilities: %s.",
            $driverKey,
            $concern,
            implode('; ', $parts),
        );
    }

    private static function render(int|float|string|bool|null $value): string
    {
        return match (true) {
            $value === null => '(undeclared)',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'{$value}'",
            default => (string) $value,
        };
    }
}
