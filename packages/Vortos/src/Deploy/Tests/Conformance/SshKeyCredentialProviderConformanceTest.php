<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\SshKeyCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Testing\CredentialProviderConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeSecretsProvider;

final class SshKeyCredentialProviderConformanceTest extends CredentialProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'ssh-key';
    }

    protected function createProvider(): CredentialProviderInterface
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'test-key-material');

        return new SshKeyCredentialProvider($secrets);
    }

    public function test_issue_returns_valid_credential(): void
    {
        $provider = $this->createProvider();
        $credential = $provider->issue(new EnvironmentName('staging'));

        $this->assertSame('ssh-key', $credential->type);
        $this->assertFalse($credential->isExpired(new \DateTimeImmutable()));
    }

    public function test_honestly_reports_unsupported_capabilities(): void
    {
        $caps = $this->createProvider()->capabilities();

        $this->assertHonestlyUnsupported($caps, CredentialCapability::NoInboundNetwork);
        $this->assertHonestlyUnsupported($caps, CredentialCapability::ShortLivedCert);
        $this->assertHonestlyUnsupported($caps, CredentialCapability::OidcFederation);
    }
}
