<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\SshKeyCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Tests\Fixtures\FakeSecretsProvider;
use Vortos\Secrets\Exception\SecretNotFoundException;

final class SshKeyCredentialProviderTest extends TestCase
{
    public function test_issue_returns_credential_from_secrets(): void
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'ssh-private-key-material');

        $provider = new SshKeyCredentialProvider($secrets);
        $credential = $provider->issue(new EnvironmentName('staging'));

        $this->assertSame('ssh-key', $credential->type);
        $this->assertSame('ssh-private-key-material', $credential->material->reveal());
        $this->assertSame('staging', $credential->issuedFor);
        $this->assertFalse($credential->isExpired(new \DateTimeImmutable()));
    }

    public function test_issue_fails_closed_on_missing_secret(): void
    {
        $secrets = new FakeSecretsProvider();
        $provider = new SshKeyCredentialProvider($secrets);

        $this->expectException(SecretNotFoundException::class);

        $provider->issue(new EnvironmentName('prod'));
    }

    public function test_lease_wipes_material_on_use(): void
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'ssh-private-key-material');

        $provider = new SshKeyCredentialProvider($secrets);
        $lease = $provider->lease(new EnvironmentName('staging'));

        $result = $lease->use(fn ($use) => $use->type());

        $this->assertSame('ssh-key', $result);
        $this->assertTrue($lease->isClosed());
    }

    public function test_lease_wipes_material_on_exception(): void
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'key');

        $provider = new SshKeyCredentialProvider($secrets);
        $lease = $provider->lease(new EnvironmentName('staging'));

        try {
            $lease->use(function () {
                throw new \RuntimeException('step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($lease->isClosed());
    }

    public function test_custom_secret_key_name(): void
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('my-custom-key', 'custom-key-material');

        $provider = new SshKeyCredentialProvider($secrets, 'my-custom-key');
        $credential = $provider->issue(new EnvironmentName('staging'));

        $this->assertSame('custom-key-material', $credential->material->reveal());
    }

    public function test_capabilities_declare_no_special_features(): void
    {
        $secrets = new FakeSecretsProvider();
        $provider = new SshKeyCredentialProvider($secrets);
        $caps = $provider->capabilities();

        $this->assertFalse($caps->supports('no_inbound_network'));
        $this->assertFalse($caps->supports('short_lived_cert'));
        $this->assertFalse($caps->supports('oidc_federation'));
    }
}
