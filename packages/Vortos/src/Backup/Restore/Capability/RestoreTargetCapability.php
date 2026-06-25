<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum RestoreTargetCapability: string implements CapabilityKey
{
    case StreamingRestore = 'streaming_restore';
    case CleanRestore = 'clean_restore';
    case PointInTime = 'point_in_time';

    public function key(): string
    {
        return $this->value;
    }
}
