<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface MetricsCollectorInterface
{
    /**
     * Refresh point-in-time gauges before export.
     */
    public function collect(): void;
}

