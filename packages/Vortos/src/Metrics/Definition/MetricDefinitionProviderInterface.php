<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

interface MetricDefinitionProviderInterface
{
    /** Service tag — any service with this tag is collected by {@see MetricDefinitionsCompilerPass}. */
    public const TAG = 'vortos.metric_definitions';

    /**
     * @return list<MetricDefinition>
     */
    public function definitions(): array;
}
