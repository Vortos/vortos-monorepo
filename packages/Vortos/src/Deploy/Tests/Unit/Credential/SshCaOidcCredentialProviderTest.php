<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\EphemeralKeyPairFactory;
use Vortos\Deploy\Credential\SshCaOidcCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Tests\Fixtures\FakeOidcTokenSource;
use Vortos\Deploy\Tests\Fixtures\FakeRegistryTokenExchange;
use Vortos\Deploy\Tests\Fixtures\FakeSshCertificateAuthority;

final class SshCaOidcCredentialProviderTest extends TestCase
{
    public function test_issue_returns_ssh_cert_credential(): void
    {
        $provider = $this->makeProvider();
        $credential = $provider->issue(new EnvironmentName('staging'));

        $this->assertSame('ssh-cert', $credential->type);
        $this->assertFalse($credential->isExpired(new \DateTimeImmutable()));
        $this->assertSame('staging', $credential->issuedFor);
    }

    public function test_issued_cert_ttl_is_within_300_seconds(): void
    {
        $provider = $this->makeProvider();
        $credential = $provider->issue(new EnvironmentName('staging'));

        $now = new \DateTimeImmutable();
        $ttl = $credential->expiresAt->getTimestamp() - $now->getTimestamp();

        $this->assertLessThanOrEqual(300, $ttl, 'Cert TTL must be <= 300 seconds.');
        $this->assertGreaterThan(0, $ttl);
    }

    public function test_cert_actually_expires(): void
    {
        $provider = $this->makeProvider(requestedTtl: 1);
        $credential = $provider->issue(new EnvironmentName('staging'));

        $futureTime = (new \DateTimeImmutable())->modify('+5 seconds');
        $this->assertTrue($credential->isExpired($futureTime));
    }

    public function test_ttl_clamped_to_max_300(): void
    {
        $provider = $this->makeProvider(requestedTtl: 9999);
        $credential = $provider->issue(new EnvironmentName('staging'));

        $now = new \DateTimeImmutable();
        $ttl = $credential->expiresAt->getTimestamp() - $now->getTimestamp();

        $this->assertLessThanOrEqual(301, $ttl, 'TTL must be clamped to <= 300 seconds.');
    }

    public function test_ca_failure_fails_closed(): void
    {
        $ca = new FakeSshCertificateAuthority();
        $ca->willFail();

        $provider = new SshCaOidcCredentialProvider(
            oidcSource: new FakeOidcTokenSource(),
            ca: $ca,
            keyPairFactory: new EphemeralKeyPairFactory(),
        );

        $this->expectException(\RuntimeException::class);

        $provider->issue(new EnvironmentName('staging'));
    }

    public function test_oidc_failure_fails_closed(): void
    {
        $oidc = new FakeOidcTokenSource();
        $oidc->willFail();

        $provider = new SshCaOidcCredentialProvider(
            oidcSource: $oidc,
            ca: new FakeSshCertificateAuthority(),
            keyPairFactory: new EphemeralKeyPairFactory(),
        );

        $this->expectException(\RuntimeException::class);

        $provider->issue(new EnvironmentName('staging'));
    }

    public function test_capabilities_declare_short_lived_cert_and_oidc(): void
    {
        $provider = $this->makeProvider();
        $caps = $provider->capabilities();

        $this->assertFalse($caps->supports('no_inbound_network'));
        $this->assertTrue($caps->supports('short_lived_cert'));
        $this->assertTrue($caps->supports('oidc_federation'));
    }

    public function test_capability_constraint_cert_ttl(): void
    {
        $provider = $this->makeProvider(requestedTtl: 120);
        $caps = $provider->capabilities();

        $this->assertSame(120, $caps->constraint('cert_ttl_seconds'));
    }

    public function test_capability_constraint_cert_ttl_clamped(): void
    {
        $provider = $this->makeProvider(requestedTtl: 9999);
        $caps = $provider->capabilities();

        $this->assertSame(300, $caps->constraint('cert_ttl_seconds'));
    }

    public function test_lease_wipes_all_secrets(): void
    {
        $provider = $this->makeProvider();
        $lease = $provider->lease(new EnvironmentName('staging'));

        $lease->use(fn ($use) => $use->type());

        $this->assertTrue($lease->isClosed());
    }

    public function test_claim_binding_staging_gets_staging_principals(): void
    {
        $ca = new FakeSshCertificateAuthority();
        $ca->bindPrincipals('staging', ['staging-deploy']);
        $ca->bindPrincipals('prod', ['prod-deploy']);

        $oidc = new FakeOidcTokenSource();

        $provider = new SshCaOidcCredentialProvider(
            oidcSource: $oidc,
            ca: $ca,
            keyPairFactory: new EphemeralKeyPairFactory(),
        );

        $credential = $provider->issue(new EnvironmentName('staging'));
        $this->assertSame('ssh-cert', $credential->type);
    }

    private function makeProvider(int $requestedTtl = 300): SshCaOidcCredentialProvider
    {
        return new SshCaOidcCredentialProvider(
            oidcSource: new FakeOidcTokenSource(),
            ca: new FakeSshCertificateAuthority(),
            keyPairFactory: new EphemeralKeyPairFactory(),
            registryExchange: new FakeRegistryTokenExchange(),
            requestedTtlSeconds: $requestedTtl,
        );
    }
}
