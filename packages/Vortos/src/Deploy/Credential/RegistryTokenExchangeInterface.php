<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Foundation\Secret\SecretValue;

interface RegistryTokenExchangeInterface
{
    public function exchange(OidcToken $oidcToken): SecretValue;
}
