<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutput
    {
        $process = new Process($command);
        if ($env !== []) {
            $process->setEnv($env);
        }
        if ($timeoutSeconds !== null) {
            $process->setTimeout($timeoutSeconds);
        }

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            throw ProcessFailedException::timeout($command[0] ?? 'unknown', $timeoutSeconds ?? 0);
        }

        return new ProcessOutput(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }
}
