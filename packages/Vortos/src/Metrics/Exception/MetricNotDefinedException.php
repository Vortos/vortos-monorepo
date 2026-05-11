<?php

declare(strict_types=1);

namespace Vortos\Metrics\Exception;

final class MetricNotDefinedException extends \InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Metric "%s" is not defined. Register it in config/metrics.php before recording it.', $name));
    }
}
