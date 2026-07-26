<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

/**
 * Runs supervisorctl via proc_open.
 *
 * The exit code is deliberately ignored: `supervisorctl status` exits non-zero whenever ANY program
 * is not RUNNING, which is exactly the condition worth reporting. Treating that as failure would
 * blind the collector precisely when something is wrong.
 */
final class ProcSupervisorCommandRunner implements SupervisorCommandRunnerInterface
{
    public function run(array $argv, float $timeoutSeconds): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes);

        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], (int) max(1, $timeoutSeconds));

        $stdout = stream_get_contents($pipes[1]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    }
}
