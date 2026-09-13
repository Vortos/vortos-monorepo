<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

use Vortos\Foundation\Secret\SecretValue;

interface CommandRunnerInterface
{
    /**
     * @param list<string>      $argv         never a secret — the launcher refuses one it can recognise
     * @param list<SecretValue> $redactTokens secrets to scrub from stored output; kept wrapped, never copied as plaintext
     */
    public function run(array $argv, string|SecretValue|null $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult;
}
