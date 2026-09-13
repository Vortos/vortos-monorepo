<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Credential\OidcToken;
use Vortos\Deploy\Credential\RegistryTokenExchangeInterface;
use Vortos\Foundation\Secret\SecretValue;

final class FakeRegistryTokenExchange implements RegistryTokenExchangeInterface
{
    public function exchange(OidcToken $oidcToken): SecretValue
    {
        return SecretValue::fromString('fake-registry-token');
    }
}
