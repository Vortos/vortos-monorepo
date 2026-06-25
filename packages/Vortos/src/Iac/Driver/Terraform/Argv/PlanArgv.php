<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform\Argv;

final class PlanArgv
{
    /** @return list<string> */
    public static function build(
        string $binary,
        string $outFile,
        int $parallelism,
        int $lockTimeoutSeconds,
        bool $destroy = false,
        bool $refreshOnly = false,
    ): array {
        $argv = [
            $binary,
            'plan',
            '-input=false',
            '-no-color',
            sprintf('-out=%s', $outFile),
            sprintf('-lock-timeout=%ds', $lockTimeoutSeconds),
            sprintf('-parallelism=%d', $parallelism),
        ];

        if ($destroy) {
            $argv[] = '-destroy';
        }

        if ($refreshOnly) {
            $argv[] = '-refresh-only';
        }

        return $argv;
    }
}
