<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\Deploy\PullAgent\DesiredStateManifestFactory;
use Vortos\Deploy\PullAgent\ManifestPublisherInterface;
use Vortos\Deploy\PullAgent\ManifestSignerInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Value\SecretValue;

#[AsDriver('pull-agent')]
final class PullAgentCredentialProvider extends AbstractCredentialProvider
{
    public function __construct(
        private readonly ?OidcTokenSourceInterface $oidcSource = null,
        private readonly ?RegistryTokenExchangeInterface $registryExchange = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [
                CredentialCapability::NoInboundNetwork->value => true,
                CredentialCapability::ShortLivedCert->value => false,
                CredentialCapability::OidcFederation->value => true,
            ],
            [
                'manifest_sig_required' => true,
            ],
        );
    }

    /**
     * Non-mutating preflight: assert the manifest-signature requirement is declared
     * (a 'pull-agent' host only applies signed desired-state). If OIDC registry
     * federation is configured, both collaborators must be present together — a
     * half-configured federation would fail to mint at deploy time. Nothing is minted
     * here: {@see OidcTokenSourceInterface::requestToken()} is never called.
     */
    public function assertIssuable(EnvironmentName $env): void
    {
        parent::assertIssuable($env);

        if ($this->capabilities()->constraint('manifest_sig_required') !== true) {
            throw CredentialNotIssuableException::forProvider(
                'pull-agent',
                'manifest signature requirement is not declared; a pull-agent host must only apply signed desired-state',
            );
        }

        if (($this->oidcSource === null) !== ($this->registryExchange === null)) {
            throw CredentialNotIssuableException::forProvider(
                'pull-agent',
                'OIDC registry federation is half-configured: both an OIDC source and a registry token exchange are required',
            );
        }
    }

    public function issue(EnvironmentName $env): IssuedCredential
    {
        $registryToken = null;
        if ($this->oidcSource !== null && $this->registryExchange !== null) {
            $oidcToken = $this->oidcSource->requestToken('registry');
            $registryToken = $this->registryExchange->exchange($oidcToken);
        }

        return new IssuedCredential(
            type: 'pull-agent',
            material: $registryToken ?? SecretValue::fromString('pull-agent-no-material'),
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            issuedFor: $env->value,
        );
    }

    protected function materialize(IssuedCredential $credential, EnvironmentName $env): CredentialLease
    {
        $use = new CredentialUse(
            credential: $credential,
            identityPath: null,
            registryToken: null,
        );

        return new CredentialLease(
            use: $use,
            secrets: [$credential->material],
        );
    }
}
