<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum MarkerCapability: string implements CapabilityKey
{
    case Annotations = 'annotations';
    case OffHost = 'off_host';
    case Tls = 'tls';

    public function key(): string
    {
        return $this->value;
    }
}
