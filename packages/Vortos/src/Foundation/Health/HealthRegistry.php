<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health;

use Vortos\Foundation\Health\Contract\HealthCheckInterface;

final class HealthRegistry
{
    /** @param HealthCheckInterface[] $checks */
    public function __construct(private readonly array $checks = []) {}

    /** @return array<string, HealthResult> */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[$check->name()] = $check->check();
        }

        return $results;
    }

    /** @param array<string, HealthResult> $results */
    public function isHealthy(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->healthy) {
                return false;
            }
        }

        return true;
    }
}
