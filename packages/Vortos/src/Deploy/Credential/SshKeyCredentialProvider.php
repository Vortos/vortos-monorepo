<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Value\SecretKey;

#[AsDriver('ssh-key')]
final class SshKeyCredentialProvider extends AbstractCredentialProvider
{
    public function __construct(
        private readonly SecretsProviderInterface $secrets,
        private readonly string $secretKeyName = 'deploy_ssh_private_key',
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            CredentialCapability::NoInboundNetwork->value => false,
            CredentialCapability::ShortLivedCert->value => false,
            CredentialCapability::OidcFederation->value => false,
        ]);
    }

    /**
     * Non-mutating preflight: assert the backing private-key secret exists — by name
     * only, via {@see SecretsProviderInterface::list()} which never reveals values —
     * so no plaintext is read and nothing is minted.
     */
    public function assertIssuable(EnvironmentName $env): void
    {
        parent::assertIssuable($env);

        foreach ($this->secrets->list() as $key) {
            if ($key->value() === $this->secretKeyName) {
                return;
            }
        }

        throw CredentialNotIssuableException::forProvider(
            'ssh-key',
            sprintf('backing secret "%s" is not present in the secrets store', $this->secretKeyName),
        );
    }

    public function issue(EnvironmentName $env): IssuedCredential
    {
        $material = $this->secrets->get(SecretKey::fromString($this->secretKeyName));

        return new IssuedCredential(
            type: 'ssh-key',
            material: $material,
            expiresAt: new \DateTimeImmutable('+1 hour'),
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
