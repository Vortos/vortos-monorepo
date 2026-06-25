<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * Resolves a {@see MarkerEmitterInterface} driver by its stable key — the Block 1
 * swappable-driver pattern, exactly mirroring {@see \Vortos\Observability\Sink\MetricsSinkRegistry}.
 */
final class MarkerEmitterRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('observability_marker_emitter', $drivers);
    }

    public function emitter(string $key): MarkerEmitterInterface
    {
        /** @var MarkerEmitterInterface */
        return $this->get($key);
    }
}
