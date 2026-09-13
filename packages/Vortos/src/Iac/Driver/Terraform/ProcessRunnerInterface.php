<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

interface ProcessRunnerInterface
{
    /**
     * @param list<string>          $argv
     * @param array<string, string|\Vortos\Foundation\Secret\SecretValue> $env secrets stay wrapped until the child's environment is written
     */
    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome;
}
