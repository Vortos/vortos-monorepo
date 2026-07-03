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
        private readonly string $knownHostsSecretName = 'deploy_known_hosts',
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

        // Fail-closed at doctor time on BOTH backing secrets: the private key AND the
        // known_hosts entries. Strict host-key verification is mandatory (no trust-on-
        // first-use), so a missing known_hosts must surface as an actionable preflight
        // failure, not a mid-deploy abort. Checked by name only via list() — never revealed.
        if (!$this->hasSecret($this->secretKeyName)) {
            throw CredentialNotIssuableException::forProvider(
                'ssh-key',
                sprintf('backing secret "%s" is not present in the secrets store', $this->secretKeyName),
            );
        }

        if (!$this->hasSecret($this->knownHostsSecretName)) {
            throw CredentialNotIssuableException::forProvider(
                'ssh-key',
                sprintf(
                    'known_hosts secret "%s" is not present; strict host-key verification is mandatory and has no '
                    . 'trust-on-first-use fallback',
                    $this->knownHostsSecretName,
                ),
            );
        }
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

    /**
     * The credential provider NEVER writes secret material to disk (enforced by
     * {@see \Vortos\Deploy\Tests\Architecture\CredentialNoStandingSecretTest}). It returns
     * the raw key + optional known_hosts material inside the lease; materializing them into
     * an ssh-usable form (an ssh-agent identity, or short-lived files under the Execution
     * layer) is the transport layer's responsibility, scoped to and wiped with the lease.
     */
    protected function materialize(IssuedCredential $credential, EnvironmentName $env): CredentialLease
    {
        $knownHosts = null;
        $secrets = [$credential->material];

        if ($this->hasSecret($this->knownHostsSecretName)) {
            $knownHosts = $this->secrets->get(SecretKey::fromString($this->knownHostsSecretName));
            $secrets[] = $knownHosts;
        }

        $use = new CredentialUse(
            credential: $credential,
            identityPath: null,
            registryToken: null,
            knownHostsMaterial: $knownHosts,
        );

        return new CredentialLease(use: $use, secrets: $secrets);
    }

    private function hasSecret(string $name): bool
    {
        foreach ($this->secrets->list() as $key) {
            if ($key->value() === $name) {
                return true;
            }
        }

        return false;
    }
}
