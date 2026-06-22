<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\SdkKey;

final readonly class SdkKey
{
    public const KIND_SERVER = 'server';
    public const KIND_CLIENT = 'client';

    public function __construct(
        public string $id,
        public string $name,
        public string $keyPrefix,
        public string $keyHash,
        public string $kind,
        public string $projectId,
        public string $environment,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public ?string $successorKeyId = null,
        public ?\DateTimeImmutable $gracePeriodEndsAt = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?\DateTimeImmutable $lastUsedAt = null,
        public ?array $ipAllowlist = null,
    ) {}

    public function isActive(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }

    public function isGracePeriodActive(): bool
    {
        return $this->gracePeriodEndsAt !== null && $this->gracePeriodEndsAt > new \DateTimeImmutable();
    }
}
