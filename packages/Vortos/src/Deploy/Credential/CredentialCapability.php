<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum CredentialCapability: string implements CapabilityKey
{
    case NoInboundNetwork = 'no_inbound_network';
    case ShortLivedCert = 'short_lived_cert';
    case OidcFederation = 'oidc_federation';

    public function key(): string
    {
        return $this->value;
    }
}
