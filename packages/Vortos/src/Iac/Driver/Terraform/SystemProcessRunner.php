<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

use Symfony\Component\Process\Process;

final class SystemProcessRunner implements ProcessRunnerInterface
{
    private const MAX_OUTPUT_BYTES = 1_048_576; // 1 MiB

    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
    {
        $start = hrtime(true);

        $process = new Process($argv, $cwd, $env);
        $process->setTimeout((float) $timeoutSeconds);

        $stdout = '';
        $stderr = '';

        $process->run(function (string $type, string $data) use (&$stdout, &$stderr): void {
            if ($type === Process::OUT) {
                if (strlen($stdout) < self::MAX_OUTPUT_BYTES) {
                    $stdout .= substr($data, 0, self::MAX_OUTPUT_BYTES - strlen($stdout));
                }
            } else {
                if (strlen($stderr) < self::MAX_OUTPUT_BYTES) {
                    $stderr .= substr($data, 0, self::MAX_OUTPUT_BYTES - strlen($stderr));
                }
            }
        });

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ProcessOutcome(
            $process->getExitCode() ?? 1,
            $stdout,
            $stderr,
            $durationMs,
        );
    }
}
