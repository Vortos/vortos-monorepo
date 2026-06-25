<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\PullAgentCredentialProvider;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Testing\CredentialProviderConformanceTestCase;

final class PullAgentCredentialProviderConformanceTest extends CredentialProviderConformanceTestCase
{
    protected function expectedKey(): string
    {
        return 'pull-agent';
    }

    protected function createProvider(): CredentialProviderInterface
    {
        return new PullAgentCredentialProvider();
    }

    public function test_issue_returns_pull_agent_credential(): void
    {
        $credential = $this->createProvider()->issue(new EnvironmentName('prod'));

        $this->assertSame('pull-agent', $credential->type);
    }

    public function test_honestly_reports_no_inbound_network(): void
    {
        $caps = $this->createProvider()->capabilities();

        $this->assertTrue($caps->supports(CredentialCapability::NoInboundNetwork));
        $this->assertHonestlyUnsupported($caps, CredentialCapability::ShortLivedCert);
        $this->assertTrue($caps->supports(CredentialCapability::OidcFederation));
    }

    public function test_manifest_sig_required_constraint(): void
    {
        $caps = $this->createProvider()->capabilities();

        $this->assertTrue($caps->constraint('manifest_sig_required'));
    }
}
