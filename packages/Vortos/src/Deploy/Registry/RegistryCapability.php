<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum RegistryCapability: string implements CapabilityKey
{
    case DigestPin = 'digest_pin';
    case MultiArch = 'multi_arch';
    case VulnerabilityScan = 'vulnerability_scan';
    case ImageSigning = 'image_signing';

    public function key(): string
    {
        return $this->value;
    }
}
