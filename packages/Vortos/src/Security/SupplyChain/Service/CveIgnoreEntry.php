<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

final readonly class CveIgnoreEntry
{
    public function __construct(
        public string $cveId,
        public string $reason,
        public \DateTimeImmutable $expiresAt,
    ) {
        if ($cveId === '') {
            throw new \InvalidArgumentException('CVE ignore entry ID must not be empty.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('CVE ignore entry must carry a reason.');
        }
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return array{cve_id: string, reason: string, expires_at: string} */
    public function toArray(): array
    {
        return [
            'cve_id' => $this->cveId,
            'reason' => $this->reason,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
