<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Process;

interface ProcessRunnerInterface
{
    /**
     * @param list<string>         $command
     * @param array<string,string> $env
     * @throws ProcessFailedException
     */
    public function run(array $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutput;
}
