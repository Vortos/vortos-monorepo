<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final readonly class CredentialUse
{
    public function __construct(
        private IssuedCredential $credential,
        private ?string $identityPath,
        private ?SecretValue $registryToken,
        private ?SecretValue $knownHostsMaterial = null,
    ) {}

    public function type(): string
    {
        return $this->credential->type;
    }

    public function identityPath(): ?string
    {
        return $this->identityPath;
    }

    /**
     * The raw identity material (private key / signed cert). Providers never write this to
     * disk (see CredentialNoStandingSecretTest); the transport layer materializes it into an
     * ssh-usable form scoped to the lease. Redacted-by-construction via {@see SecretValue}.
     */
    public function identityMaterial(): SecretValue
    {
        return $this->credential->material;
    }

    /**
     * The known_hosts entries for strict host-key verification, or null when the provider
     * supplies none. Public host keys, but carried as a SecretValue for uniform handling.
     */
    public function knownHostsMaterial(): ?SecretValue
    {
        return $this->knownHostsMaterial;
    }

    public function registryToken(): ?SecretValue
    {
        return $this->registryToken;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->credential->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->credential->isExpired($now);
    }
}
