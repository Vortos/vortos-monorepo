<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

enum WorkerRuntimeStatus: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Stopping = 'stopping';
    case Exited = 'exited';
    case Fatal = 'fatal';
    case Unknown = 'unknown';

    public function isRunning(): bool
    {
        return $this === self::Running;
    }

    public function isStopped(): bool
    {
        return $this === self::Stopped || $this === self::Exited;
    }
}
