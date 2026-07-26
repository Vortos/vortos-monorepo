<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

/**
 * One row of `supervisorctl status`.
 *
 * @see SupervisorStatusReader for how these are parsed.
 */
final readonly class SupervisorProcessStatus
{
    public function __construct(
        public string $program,
        public string $state,
        public ?int $pid,
        public ?int $uptimeSeconds,
    ) {}

    /**
     * Supervisor's own definition of healthy. Every other state — STARTING, BACKOFF, FATAL,
     * EXITED, STOPPED — means this program is not currently doing its job.
     */
    public function isRunning(): bool
    {
        return $this->state === 'RUNNING';
    }
}
