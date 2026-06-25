<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform\Argv;

final class DestroyArgv
{
    /** @return list<string> */
    public static function buildPlan(
        string $binary,
        string $outFile,
        int $parallelism,
        int $lockTimeoutSeconds,
    ): array {
        return PlanArgv::build($binary, $outFile, $parallelism, $lockTimeoutSeconds, destroy: true);
    }
}
