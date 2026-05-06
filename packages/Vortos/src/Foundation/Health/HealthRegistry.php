<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health;

use Vortos\Foundation\Health\Contract\HealthCheckInterface;

final class HealthRegistry
{
    /** @param array<int, HealthCheckInterface|array{check: HealthCheckInterface, critical?: bool, timeout_ms?: int}> $checks */
    public function __construct(private readonly array $checks = []) {}

    /** @return array<string, HealthResult> */
    public function run(bool $criticalOnly = false): array
    {
        $results = [];

        foreach ($this->checks as $entry) {
            [$check, $critical, $timeoutMs] = $this->normalize($entry);

            if ($criticalOnly && !$critical) {
                continue;
            }

            $results[$check->name()] = $check->check()->withRuntimeMetadata($critical, $timeoutMs);
        }

        return $results;
    }

    /** @param array<string, HealthResult> $results */
    public function isHealthy(array $results, bool $criticalOnly = true): bool
    {
        foreach ($results as $result) {
            if ($criticalOnly && !$result->critical) {
                continue;
            }

            if (!$result->healthy) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param HealthCheckInterface|array{check: HealthCheckInterface, critical?: bool, timeout_ms?: int} $entry
     * @return array{HealthCheckInterface, bool, int}
     */
    private function normalize(HealthCheckInterface|array $entry): array
    {
        if ($entry instanceof HealthCheckInterface) {
            return [$entry, true, 5000];
        }

        return [
            $entry['check'],
            (bool) ($entry['critical'] ?? true),
            max(1, (int) ($entry['timeout_ms'] ?? 5000)),
        ];
    }
}
