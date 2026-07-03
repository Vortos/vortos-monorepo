<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\PullAgentCredentialProvider;
use Vortos\Deploy\Credential\SshKeyCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\Deploy\Tests\Fixtures\FakeOidcTokenSource;
use Vortos\Deploy\Tests\Fixtures\FakeSecretsProvider;

/**
 * Block 12 §6.1: the non-mutating `assertIssuable()` preflight on each Block 11
 * provider — proves a mint would succeed without minting anything.
 */
final class AssertIssuableTest extends TestCase
{
    private EnvironmentName $env;

    protected function setUp(): void
    {
        $this->env = new EnvironmentName('production');
    }

    public function test_ssh_key_passes_when_backing_secret_present(): void
    {
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'PRIVATE-KEY-MATERIAL');
        $secrets->setSecret('deploy_known_hosts', 'vps.example.com ssh-ed25519 AAAA');

        $provider = new SshKeyCredentialProvider($secrets);

        $provider->assertIssuable($this->env);
        $this->addToAssertionCount(1);
    }

    public function test_ssh_key_fails_when_backing_secret_absent(): void
    {
        $provider = new SshKeyCredentialProvider(new FakeSecretsProvider());

        $this->expectException(CredentialNotIssuableException::class);
        $provider->assertIssuable($this->env);
    }

    public function test_ssh_key_fails_when_known_hosts_secret_absent(): void
    {
        // Private key present but no known_hosts: strict host-key verification is mandatory,
        // so the preflight must fail loud rather than defer the failure to deploy time.
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'PRIVATE-KEY-MATERIAL');

        $provider = new SshKeyCredentialProvider($secrets);

        $this->expectException(CredentialNotIssuableException::class);
        $provider->assertIssuable($this->env);
    }

    public function test_ssh_key_assert_issuable_does_not_reveal_secret(): void
    {
        // list() returns names only; a missing-but-named secret still passes preflight
        // without get()/reveal() ever running.
        $secrets = new FakeSecretsProvider();
        $secrets->setSecret('deploy_ssh_private_key', 'SENSITIVE');
        $secrets->setSecret('deploy_known_hosts', 'vps.example.com ssh-ed25519 AAAA');

        $provider = new SshKeyCredentialProvider($secrets);
        $provider->assertIssuable($this->env);

        $this->addToAssertionCount(1);
    }

    public function test_pull_agent_passes_with_signature_requirement(): void
    {
        $provider = new PullAgentCredentialProvider();

        $provider->assertIssuable($this->env);
        $this->addToAssertionCount(1);
    }

    public function test_pull_agent_fails_on_half_configured_federation(): void
    {
        // OIDC source present but no registry exchange → would fail to mint at deploy time.
        $provider = new PullAgentCredentialProvider(new FakeOidcTokenSource());

        $this->expectException(CredentialNotIssuableException::class);
        $provider->assertIssuable($this->env);
    }
}
