<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\EphemeralKeyPairFactory;
use Vortos\Deploy\Credential\SshCaOidcCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Testing\CredentialProviderConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeOidcTokenSource;
use Vortos\Deploy\Tests\Fixtures\FakeRegistryTokenExchange;
use Vortos\Deploy\Tests\Fixtures\FakeSshCertificateAuthority;

final class SshCaOidcCredentialProviderConformanceTest extends CredentialProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'ssh-ca-oidc';
    }

    protected function createProvider(): CredentialProviderInterface
    {
        return new SshCaOidcCredentialProvider(
            oidcSource: new FakeOidcTokenSource(),
            ca: new FakeSshCertificateAuthority(),
            keyPairFactory: new EphemeralKeyPairFactory(),
            registryExchange: new FakeRegistryTokenExchange(),
        );
    }

    public function test_issue_returns_ssh_cert(): void
    {
        $credential = $this->createProvider()->issue(new EnvironmentName('staging'));

        $this->assertSame('ssh-cert', $credential->type);
    }

    public function test_cert_ttl_within_300_seconds(): void
    {
        $credential = $this->createProvider()->issue(new EnvironmentName('staging'));

        $now = new \DateTimeImmutable();
        $ttl = $credential->expiresAt->getTimestamp() - $now->getTimestamp();

        $this->assertLessThanOrEqual(300, $ttl);
    }

    public function test_honestly_reports_supported_capabilities(): void
    {
        $caps = $this->createProvider()->capabilities();

        $this->assertHonestlyUnsupported($caps, CredentialCapability::NoInboundNetwork);
        $this->assertTrue($caps->supports(CredentialCapability::ShortLivedCert));
        $this->assertTrue($caps->supports(CredentialCapability::OidcFederation));
    }

    public function test_cert_ttl_constraint_declared(): void
    {
        $caps = $this->createProvider()->capabilities();
        $ttl = $caps->constraint('cert_ttl_seconds');

        $this->assertNotNull($ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }
}
