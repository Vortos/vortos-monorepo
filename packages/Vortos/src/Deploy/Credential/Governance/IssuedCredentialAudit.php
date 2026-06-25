<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential\Governance;

final readonly class IssuedCredentialAudit
{
    public function __construct(
        public string $actorId,
        public string $environment,
        public string $credentialType,
        public int $ttlSeconds,
        public ?string $certFingerprint,
        public \DateTimeImmutable $issuedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'environment' => $this->environment,
            'credential_type' => $this->credentialType,
            'ttl_seconds' => $this->ttlSeconds,
            'cert_fingerprint' => $this->certFingerprint,
            'issued_at' => $this->issuedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
