<?php

declare(strict_types=1);

namespace Vortos\Secrets\Provider;

/**
 * Maps an **environment name** (e.g. `production`, `staging`) to the secrets **driver key** that
 * serves it.
 *
 * `secrets:preflight` previously passed `--env` straight to the driver registry, so
 * `--env=production` looked up a driver literally named "production" — which does not exist, and the
 * documented production gate threw `UnknownDriverException` (B4). Environment and driver are distinct
 * concepts: this resolver bridges them, defaulting every environment to the zero-config `env` driver
 * (which is where production runtime secrets come from — envelope-decrypted at boot). Apps running
 * more than one custody backend can override per environment.
 */
final readonly class EnvironmentProviderResolver
{
    /** @param array<string, string> $environmentToDriver */
    public function __construct(
        private array $environmentToDriver = [],
        private string $defaultDriver = 'env',
    ) {}

    public function driverFor(string $environment): string
    {
        return $this->environmentToDriver[$environment] ?? $this->defaultDriver;
    }

    public function hasExplicitMapping(string $environment): bool
    {
        return isset($this->environmentToDriver[$environment]);
    }

    /**
     * Parse a `env1:driver1,env2:driver2` spec (e.g. from VORTOS_SECRETS_ENVIRONMENT_DRIVERS).
     */
    public static function fromSpec(string $spec, string $defaultDriver = 'env'): self
    {
        $map = [];
        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        return new self($map, $defaultDriver);
    }
}
