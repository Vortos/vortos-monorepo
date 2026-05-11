<?php

declare(strict_types=1);

namespace Vortos\Metrics\Exception;

final class MetricLabelValueException extends \InvalidArgumentException
{
    public function __construct(string $metricName, string $labelName)
    {
        parent::__construct(sprintf(
            'Metric "%s" label "%s" value must be scalar/stringable and must not contain control characters, pipes, or commas.',
            $metricName,
            $labelName,
        ));
    }
}
