<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves a {@see MetricsSinkInterface} driver by its stable key. Backed by a
 * compile-time-collected ServiceLocator; only installed drivers register, with zero
 * runtime reflection (Golden Rule #1).
 */
final class MetricsSinkRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('observability_metrics_sink', $drivers);
    }

    public function sink(string $key): MetricsSinkInterface
    {
        /** @var MetricsSinkInterface */
        return $this->get($key);
    }

    /** @return list<MetricsSinkInterface> */
    public function allSinks(): array
    {
        $sinks = [];
        foreach ($this->keys() as $key) {
            $sinks[] = $this->sink($key);
        }

        return $sinks;
    }
}
