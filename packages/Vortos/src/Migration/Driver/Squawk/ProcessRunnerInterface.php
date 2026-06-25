<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\Squawk;

interface ProcessRunnerInterface
{
    /** @return array{exitCode: int, stdout: string, stderr: string} */
    public function run(string $binary, string $stdin, int $timeoutSeconds): array;
}
