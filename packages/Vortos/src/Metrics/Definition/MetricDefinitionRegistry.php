<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

use Vortos\Metrics\Exception\MetricLabelMismatchException;
use Vortos\Metrics\Exception\MetricNotDefinedException;
use Vortos\Metrics\Exception\MetricLabelValueException;
use Vortos\Metrics\Exception\MetricTypeMismatchException;

final class MetricDefinitionRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<MetricDefinition> $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->name])) {
                throw new \InvalidArgumentException(sprintf('Duplicate metric definition "%s".', $definition->name));
            }

            $this->definitions[$definition->name] = $definition;
        }
    }

    public function get(string $name): MetricDefinition
    {
        return $this->definitions[$name] ?? throw new MetricNotDefinedException($name);
    }

    public function requireType(string $name, MetricType $type): MetricDefinition
    {
        $definition = $this->get($name);
        if ($definition->type !== $type) {
            throw new MetricTypeMismatchException($name, $type, $definition->type);
        }

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    public function validateLabels(MetricDefinition $definition, array $labels): array
    {
        $expected = $definition->labelNames;
        $actual = array_keys($labels);

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        if ($missing !== [] || $extra !== []) {
            throw new MetricLabelMismatchException($definition->name, $expected, $actual);
        }

        $ordered = [];
        foreach ($expected as $labelName) {
            $ordered[$labelName] = $this->normalizeLabelValue($definition->name, $labelName, $labels[$labelName]);
        }

        return $ordered;
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    private function normalizeLabelValue(string $metricName, string $labelName, mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new MetricLabelValueException($metricName, $labelName);
        }

        $value = (string) $value;
        if (preg_match('/[\r\n|,\x00-\x1F\x7F]/', $value)) {
            throw new MetricLabelValueException($metricName, $labelName);
        }

        return $value;
    }
}
