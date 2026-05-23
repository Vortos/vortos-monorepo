<?php

declare(strict_types=1);

namespace Vortos\Foundation\Doctor;

use Vortos\Foundation\Doctor\Contract\DoctorCheckInterface;

final class DoctorRegistry
{
    /** @param DoctorCheckInterface[] $checks */
    public function __construct(private readonly array $checks = []) {}

    /** @return DoctorResult[] */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[] = $check->run();
            } catch (\Throwable $e) {
                $results[] = DoctorResult::error(
                    $check->name(),
                    'Check threw an exception: ' . $e->getMessage(),
                    'Fix the check implementation — it must not throw.',
                );
            }
        }

        return $results;
    }

    public function hasChecks(): bool
    {
        return $this->checks !== [];
    }
}
