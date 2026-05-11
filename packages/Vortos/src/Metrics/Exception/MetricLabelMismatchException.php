<?php

declare(strict_types=1);

namespace Vortos\Metrics\Exception;

final class MetricLabelMismatchException extends \InvalidArgumentException
{
    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    public function __construct(string $name, array $expected, array $actual)
    {
        parent::__construct(sprintf(
            'Metric "%s" labels must exactly match [%s]; got [%s].',
            $name,
            implode(', ', $expected),
            implode(', ', $actual),
        ));
    }
}
