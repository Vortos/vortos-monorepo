<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Credential;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\PullAgentCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Tests\Fixtures\FakeOidcTokenSource;
use Vortos\Deploy\Tests\Fixtures\FakeRegistryTokenExchange;

final class PullAgentCredentialProviderTest extends TestCase
{
    public function test_issue_returns_pull_agent_credential(): void
    {
        $provider = new PullAgentCredentialProvider();
        $credential = $provider->issue(new EnvironmentName('prod'));

        $this->assertSame('pull-agent', $credential->type);
        $this->assertSame('prod', $credential->issuedFor);
    }

    public function test_issue_with_oidc_exchanges_registry_token(): void
    {
        $provider = new PullAgentCredentialProvider(
            oidcSource: new FakeOidcTokenSource(),
            registryExchange: new FakeRegistryTokenExchange(),
        );

        $credential = $provider->issue(new EnvironmentName('prod'));

        $this->assertSame('pull-agent', $credential->type);
        $this->assertSame('fake-registry-token', $credential->material->reveal());
    }

    public function test_capabilities_declare_no_inbound_network(): void
    {
        $provider = new PullAgentCredentialProvider();
        $caps = $provider->capabilities();

        $this->assertTrue($caps->supports('no_inbound_network'));
        $this->assertFalse($caps->supports('short_lived_cert'));
        $this->assertTrue($caps->supports('oidc_federation'));
        $this->assertTrue($caps->constraint('manifest_sig_required'));
    }

    public function test_lease_wipes_on_use(): void
    {
        $provider = new PullAgentCredentialProvider();
        $lease = $provider->lease(new EnvironmentName('prod'));

        $lease->use(fn ($use) => null);

        $this->assertTrue($lease->isClosed());
    }
}
