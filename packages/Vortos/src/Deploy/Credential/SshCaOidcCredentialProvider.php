<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\CredentialNotIssuableException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('ssh-ca-oidc')]
final class SshCaOidcCredentialProvider extends AbstractCredentialProvider
{
    private const MAX_TTL_SECONDS = 300;

    public function __construct(
        private readonly OidcTokenSourceInterface $oidcSource,
        private readonly SshCertificateAuthorityInterface $ca,
        private readonly EphemeralKeyPairFactory $keyPairFactory,
        private readonly ?RegistryTokenExchangeInterface $registryExchange = null,
        private readonly int $requestedTtlSeconds = self::MAX_TTL_SECONDS,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [
                CredentialCapability::NoInboundNetwork->value => false,
                CredentialCapability::ShortLivedCert->value => true,
                CredentialCapability::OidcFederation->value => true,
            ],
            [
                'cert_ttl_seconds' => min($this->requestedTtlSeconds, self::MAX_TTL_SECONDS),
            ],
        );
    }

    public function assertIssuable(EnvironmentName $env): void
    {
        parent::assertIssuable($env);

        $ttl = min($this->requestedTtlSeconds, self::MAX_TTL_SECONDS);
        if ($ttl <= 0) {
            throw CredentialNotIssuableException::forProvider(
                'ssh-ca-oidc',
                sprintf('certificate TTL must be positive, got %d seconds', $ttl),
            );
        }
    }

    public function issue(EnvironmentName $env): IssuedCredential
    {
        $oidcToken = $this->oidcSource->requestToken('deploy');
        $keyPair = $this->keyPairFactory->generate();
        $ttl = min($this->requestedTtlSeconds, self::MAX_TTL_SECONDS);

        $cert = $this->ca->sign($keyPair->publicKey, $oidcToken, $ttl);

        $keyPair->privateKey->wipe();

        return new IssuedCredential(
            type: 'ssh-cert',
            material: $cert->certBlob,
            expiresAt: $cert->validBefore,
            issuedFor: $env->value,
        );
    }

    public function lease(EnvironmentName $env): CredentialLease
    {
        $oidcToken = $this->oidcSource->requestToken('deploy');
        $keyPair = $this->keyPairFactory->generate();
        $ttl = min($this->requestedTtlSeconds, self::MAX_TTL_SECONDS);

        $cert = $this->ca->sign($keyPair->publicKey, $oidcToken, $ttl);

        $registryToken = null;
        if ($this->registryExchange !== null) {
            $registryToken = $this->registryExchange->exchange($oidcToken);
        }

        $use = new CredentialUse(
            credential: new IssuedCredential(
                type: 'ssh-cert',
                material: $cert->certBlob,
                expiresAt: $cert->validBefore,
                issuedFor: $env->value,
            ),
            identityPath: null,
            registryToken: $registryToken,
        );

        $secrets = [$keyPair->privateKey, $cert->certBlob, $oidcToken->rawJwt];
        if ($registryToken !== null) {
            $secrets[] = $registryToken;
        }

        return new CredentialLease(
            use: $use,
            secrets: $secrets,
        );
    }

    protected function materialize(IssuedCredential $credential, EnvironmentName $env): CredentialLease
    {
        return $this->lease($env);
    }
}
