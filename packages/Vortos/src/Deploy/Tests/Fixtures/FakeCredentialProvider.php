<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Credential\CredentialCapability;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\IssuedCredential;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Value\SecretValue;

#[AsDriver('fake-credential')]
final class FakeCredentialProvider implements CredentialProviderInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            CredentialCapability::NoInboundNetwork->value => false,
            CredentialCapability::ShortLivedCert->value => false,
            CredentialCapability::OidcFederation->value => false,
        ]);
    }

    public function assertIssuable(EnvironmentName $env): void
    {
        // Fake provider is always issuable — non-mutating, mints nothing.
    }

    public function issue(EnvironmentName $env): IssuedCredential
    {
        return new IssuedCredential(
            type: 'ssh-key',
            material: SecretValue::fromString('fake-key-material'),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            issuedFor: $env->value,
        );
    }
}
