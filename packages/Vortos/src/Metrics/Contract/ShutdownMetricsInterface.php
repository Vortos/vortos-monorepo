<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface ShutdownMetricsInterface
{
    public function shutdown(): void;
}
