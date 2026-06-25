<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

use Symfony\Component\Process\Process;

final class ProcessCommandRunner implements CommandRunnerInterface
{
    private const DEFAULT_TIMEOUT = 300.0;

    public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('argv must not be empty.');
        }

        $process = new Process($argv);
        $process->setTimeout($timeout ?? self::DEFAULT_TIMEOUT);

        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        $start = microtime(true);
        $process->run();
        $duration = microtime(true) - $start;

        return new CommandResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            duration: $duration,
            redactTokens: $redactTokens,
        );
    }
}
