<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class CredentialProviderConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createProvider(): CredentialProviderInterface;

    protected function createDriver(): CredentialProviderInterface
    {
        return $this->createProvider();
    }

    final public function test_provider_declares_no_inbound_network_capability(): void
    {
        $caps = $this->createProvider()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(CredentialCapability::NoInboundNetwork->value, $caps);
    }

    final public function test_provider_declares_short_lived_cert_capability(): void
    {
        $caps = $this->createProvider()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(CredentialCapability::ShortLivedCert->value, $caps);
    }
}
