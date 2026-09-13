<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\Squawk;

use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;

final class SystemProcessRunner implements ProcessRunnerInterface
{
    private const MAX_OUTPUT_BYTES = 1_048_576; // 1 MiB

    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    public function run(string $binary, string $stdin, int $timeoutSeconds): array
    {
        $result = $this->launcher->run(
            new ProcessSpec(
                [$binary, '--reporter', 'json', '--stdin-filepath', 'migration.sql'],
                EnvironmentPolicy::Minimal,
                (float) $timeoutSeconds,
                maxOutputBytes: self::MAX_OUTPUT_BYTES,
            ),
            $stdin,
        );

        return [
            'exitCode' => $result->timedOut ? 1 : $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->timedOut ? 'squawk timed out after ' . $timeoutSeconds . 's' : $result->stderr,
        ];
    }
}
