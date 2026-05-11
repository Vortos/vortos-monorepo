<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

interface MetricDefinitionProviderInterface
{
    /**
     * @return list<MetricDefinition>
     */
    public function definitions(): array;
}
