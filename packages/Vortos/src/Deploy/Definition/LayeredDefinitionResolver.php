<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

final class LayeredDefinitionResolver
{
    public function __construct(
        private readonly DeploymentDefinitionBuilder $baseBuilder,
    ) {}

    public function resolve(string $environment): DeploymentDefinition
    {
        return $this->baseBuilder->buildForEnvironment($environment);
    }

    public function driftReport(string $environment): DefinitionDriftReport
    {
        $base = $this->baseBuilder->build();
        $effective = $this->resolve($environment);

        $baseArray = $base->toArray();
        $effectiveArray = $effective->toArray();

        $diffs = [];
        foreach ($effectiveArray as $key => $effectiveValue) {
            $baseValue = $baseArray[$key] ?? null;
            if ($effectiveValue !== $baseValue) {
                $diffs[$key] = ['base' => $baseValue, 'override' => $effectiveValue];
            }
        }

        return new DefinitionDriftReport($environment, $diffs);
    }
}
