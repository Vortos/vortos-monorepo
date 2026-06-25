<?php

declare(strict_types=1);

namespace Vortos\Secrets\Key;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum KeyProviderCapability: string implements CapabilityKey
{
    case OffHostKey = 'off_host_key';
    case Wrap = 'wrap';
    case Unwrap = 'unwrap';
    case EnvelopeEncryption = 'envelope_encryption';

    public function key(): string
    {
        return $this->value;
    }
}
