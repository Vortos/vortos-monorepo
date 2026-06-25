<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker\Driver\Null;

use Vortos\Observability\Marker\Capability\MarkerCapability;
use Vortos\Observability\Marker\DeployMarker;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Explicit no-op marker emitter — the default when no off-host plane is configured.
 */
#[AsDriver('null')]
final class NullMarkerEmitter implements MarkerEmitterInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function emit(DeployMarker $marker): void
    {
        // Intentionally discarded.
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            MarkerCapability::Annotations->value => false,
            MarkerCapability::OffHost->value => false,
            MarkerCapability::Tls->value => false,
        ]);
    }
}
