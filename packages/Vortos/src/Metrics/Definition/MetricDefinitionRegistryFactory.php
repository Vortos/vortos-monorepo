<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

final class MetricDefinitionRegistryFactory
{
    /**
     * @param list<array{type: string, name: string, help: string, label_names?: list<string>, buckets?: list<float|int>}> $definitions
     */
    public static function create(array $definitions): MetricDefinitionRegistry
    {
        return new MetricDefinitionRegistry(array_map(
            static fn (array $definition): MetricDefinition => MetricDefinition::fromArray($definition),
            $definitions,
        ));
    }
}
