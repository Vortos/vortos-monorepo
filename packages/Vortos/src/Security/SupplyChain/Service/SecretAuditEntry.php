<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

final readonly class SecretAuditEntry
{
    public function __construct(
        public string $id,
        public ?int $rotationIntervalSeconds = null,
        public ?\DateTimeImmutable $lastRotatedAt = null,
        public ?string $rawValue = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('SecretAuditEntry id must not be empty.');
        }
    }
}
