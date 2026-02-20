<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Definition\Transport;

/**
 * Factory that constructs a transport definition from a raw config array.
 *
 * Used by the compiler pass when building definitions from array-based config
 * (e.g. environment-driven config rather than fluent PHP builders).
 * One factory per driver. The correct factory is selected via supports().
 */
interface TransportDefinitionFactoryInterface
{
    /** Returns true if this factory can handle the given driver string (e.g. 'kafka', 'rabbitmq'). */
    public function supports(string $driver): bool;

    /** Build a transport definition from a normalized config array for the given transport name. */
    public function create(string $name, array $config): AbstractTransportDefinition;
}