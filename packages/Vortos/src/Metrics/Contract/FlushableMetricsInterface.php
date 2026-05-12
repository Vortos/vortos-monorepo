<?php

declare(strict_types=1);

namespace Vortos\Metrics\Contract;

interface FlushableMetricsInterface
{
    public function flush(): void;
}
