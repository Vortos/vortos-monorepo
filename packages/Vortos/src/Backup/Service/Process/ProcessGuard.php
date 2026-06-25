<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\Process;

use Vortos\Backup\Domain\Exception\DumpFailedException;

/**
 * Owns a `proc_open` handle + its stderr pipe so the runner can confirm a dump
 * subprocess exited 0 *after* its stdout has been streamed to the store.
 *
 * This is the mechanism that turns a mid-stream pg_dump/mongodump failure into a loud
 * {@see DumpFailedException} rather than a silently-truncated "successful" backup.
 */
final class ProcessGuard
{
    private bool $closed = false;

    /**
     * @param resource $process the proc_open() handle
     * @param resource $stderr  the process stderr pipe
     */
    public function __construct(
        private mixed $process,
        private mixed $stderr,
        private readonly string $engine,
    ) {}

    public function assertSuccess(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $stderr = is_resource($this->stderr) ? (string) stream_get_contents($this->stderr) : '';
        if (is_resource($this->stderr)) {
            fclose($this->stderr);
        }

        $exitCode = is_resource($this->process) ? proc_close($this->process) : 0;

        if ($exitCode !== 0) {
            throw DumpFailedException::process($this->engine, $exitCode, $stderr);
        }
    }
}
