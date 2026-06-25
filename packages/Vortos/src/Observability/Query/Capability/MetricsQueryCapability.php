<?php

declare(strict_types=1);

namespace Vortos\Observability\Query\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum MetricsQueryCapability: string implements CapabilityKey
{
    case InstantQuery = 'instant_query';
    case RangeQuery = 'range_query';
    case Quantiles = 'quantiles';
    case LabelFilter = 'label_filter';

    public function key(): string
    {
        return $this->value;
    }
}
