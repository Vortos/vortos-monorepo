<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Security\SupplyChain\Model\Vulnerability\Severity;

final readonly class CveGatePolicy
{
    /** @param list<CveIgnoreEntry> $ignoreList */
    public function __construct(
        public Severity $failOn = Severity::Critical,
        public bool $failOnKevAnySeverity = true,
        public bool $requireFixAvailable = false,
        public array $ignoreList = [],
    ) {}

    public function isIgnored(string $cveId, \DateTimeImmutable $now): bool
    {
        foreach ($this->ignoreList as $entry) {
            if ($entry->cveId === $cveId && !$entry->isExpired($now)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{fail_on: string, fail_on_kev_any_severity: bool, require_fix_available: bool, ignore_count: int} */
    public function toArray(): array
    {
        return [
            'fail_on' => $this->failOn->value,
            'fail_on_kev_any_severity' => $this->failOnKevAnySeverity,
            'require_fix_available' => $this->requireFixAvailable,
            'ignore_count' => count($this->ignoreList),
        ];
    }
}
