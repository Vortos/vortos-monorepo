<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

interface CommandRunnerInterface
{
    /**
     * @param list<string> $argv
     * @param list<string> $redactTokens Tokens to redact from stored output
     */
    public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult;
}
