<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

interface RegistryTokenExchangeInterface
{
    public function exchange(OidcToken $oidcToken): SecretValue;
}
