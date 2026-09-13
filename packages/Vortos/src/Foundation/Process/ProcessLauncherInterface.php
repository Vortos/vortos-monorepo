<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use Vortos\Foundation\Secret\SecretValue;

/**
 * The only way Vortos code starts a process. Raw primitives (proc_open, exec, shell_exec, system,
 * passthru, popen, backticks, Symfony Process) are refused everywhere else by
 * `Vortos\OpsKit\PHPStan\ProcessPrimitiveRule` and `NoRawProcessPrimitivesTest`.
 */
interface ProcessLauncherInterface
{
    /** Run to completion with buffered, capped, redacted output. Honours the spec's timeout. */
    public function run(ProcessSpec $spec, string|SecretValue|null $stdin = null): ProcessResult;

    /**
     * Start for streaming. The spec's timeout must be null — a stream's duration belongs to its consumer.
     * stderr may not be a Pipe (it can deadlock a caller reading stdout); use Capture.
     */
    public function start(ProcessSpec $spec, StreamMode $stdin, StreamMode $stdout, StreamMode $stderr): RunningProcess;

    /** Resolve a program on PATH without a shell. Null when absent or not executable. */
    public function which(string $program): ?string;
}
