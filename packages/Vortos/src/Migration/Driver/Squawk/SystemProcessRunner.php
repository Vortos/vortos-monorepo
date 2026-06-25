<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\Squawk;

use Symfony\Component\Process\Process;

final class SystemProcessRunner implements ProcessRunnerInterface
{
    private const MAX_OUTPUT_BYTES = 1_048_576; // 1 MiB

    public function run(string $binary, string $stdin, int $timeoutSeconds): array
    {
        $process = new Process(
            [$binary, '--reporter', 'json', '--stdin-filepath', 'migration.sql'],
            timeout: $timeoutSeconds,
        );
        $process->setInput($stdin);
        $process->run();

        $stdout = substr($process->getOutput(), 0, self::MAX_OUTPUT_BYTES);
        $stderr = substr($process->getErrorOutput(), 0, self::MAX_OUTPUT_BYTES);

        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
