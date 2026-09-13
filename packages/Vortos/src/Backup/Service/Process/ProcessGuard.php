<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\Process;

use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Foundation\Process\RunningProcess;

/**
 * Confirms a dump/restore subprocess exited 0 *after* its stream has been consumed.
 *
 * This is the mechanism that turns a mid-stream pg_dump/mongodump failure into a loud
 * {@see DumpFailedException} rather than a silently-truncated "successful" backup. The stderr it
 * reports is the launcher's capture: tail-capped and scrubbed of every credential the child was given.
 */
final class ProcessGuard
{
    public function __construct(
        private readonly RunningProcess $process,
        private readonly string $engine,
    ) {}

    public function assertSuccess(): void
    {
        $exit = $this->process->wait();

        if (!$exit->isSuccessful()) {
            throw DumpFailedException::process($this->engine, $exit->exitCode, $exit->stderr);
        }
    }
}
