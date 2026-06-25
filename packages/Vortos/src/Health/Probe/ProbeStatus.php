<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

enum ProbeStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function isHealthy(): bool
    {
        return $this !== self::Fail;
    }
}
