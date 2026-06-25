<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry\Auth;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum RegistryAuthCapability: string implements CapabilityKey
{
    /**
     * The strategy can exchange an OIDC token for a registry token at runtime,
     * removing the need for a long-lived static secret.
     */
    case OidcExchange = 'oidc-exchange';

    public function key(): string
    {
        return $this->value;
    }
}
