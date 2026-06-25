<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

interface ProcessRunnerInterface
{
    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome;
}
