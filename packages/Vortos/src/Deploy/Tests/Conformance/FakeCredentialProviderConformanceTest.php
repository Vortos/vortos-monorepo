<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Testing\CredentialProviderConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeCredentialProvider;

final class FakeCredentialProviderConformanceTest extends CredentialProviderConformanceTestCase
{
    protected function createProvider(): CredentialProviderInterface
    {
        return new FakeCredentialProvider();
    }

    protected function expectedKey(): string
    {
        return 'fake-credential';
    }

    public function test_honestly_reports_no_inbound_network(): void
    {
        $provider = new FakeCredentialProvider();
        $this->assertHonestlyUnsupported($provider->capabilities(), 'no_inbound_network');
    }
}
