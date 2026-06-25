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
    ) {}

    public function type(): string
    {
        return $this->credential->type;
    }

    public function identityPath(): ?string
    {
        return $this->identityPath;
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
