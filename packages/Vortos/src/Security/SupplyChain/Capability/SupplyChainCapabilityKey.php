<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum SupplyChainCapabilityKey: string implements CapabilityKey
{
    case Sbom = 'sbom';
    case Signing = 'signing';
    case KeylessSigning = 'keyless_signing';
    case RekorTransparency = 'rekor_transparency';
    case Scanning = 'scanning';
    case KevAware = 'kev_aware';
    case RuntimeRescan = 'runtime_rescan';

    public function key(): string
    {
        return $this->value;
    }
}
