<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

use Throwable;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;

/**
 * Runs supervisorctl through the framework launcher, with a minimal environment — supervisorctl reads
 * its socket from its own config file and needs none of the application's secrets.
 *
 * The exit code is deliberately ignored: `supervisorctl status` exits non-zero whenever ANY program
 * is not RUNNING, which is exactly the condition worth reporting. Treating that as failure would
 * blind the collector precisely when something is wrong.
 */
final class ProcSupervisorCommandRunner implements SupervisorCommandRunnerInterface
{
    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    public function run(array $argv, float $timeoutSeconds): string
    {
        if ($argv === []) {
            return '';
        }

        try {
            return $this->launcher->run(new ProcessSpec($argv, EnvironmentPolicy::Minimal, max(0.001, $timeoutSeconds)))->stdout;
        } catch (Throwable) {
            return '';
        }
    }
}
