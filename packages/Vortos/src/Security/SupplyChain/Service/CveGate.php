<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Security\SupplyChain\Model\Attestation\CveGateDecision;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;
use Vortos\Security\SupplyChain\Model\Vulnerability\Vulnerability;
use Vortos\Security\SupplyChain\Model\Vulnerability\VulnerabilityReport;

final class CveGate
{
    public function evaluate(
        VulnerabilityReport $report,
        ?KevCatalog $kev,
        CveGatePolicy $policy,
        \DateTimeImmutable $now,
    ): CveGateDecision {
        $reasons = [];
        $offending = [];

        foreach ($report->vulnerabilities as $vuln) {
            if ($policy->isIgnored($vuln->id, $now)) {
                continue;
            }

            if ($this->shouldFail($vuln, $kev, $policy)) {
                $reasons[] = $this->formatReason($vuln, $kev);
                $offending[] = $vuln->id;
            }
        }

        if ($reasons === []) {
            return CveGateDecision::passed();
        }

        return CveGateDecision::failed($reasons, array_unique($offending));
    }

    private function shouldFail(Vulnerability $vuln, ?KevCatalog $kev, CveGatePolicy $policy): bool
    {
        $isKev = $kev !== null && $kev->contains($vuln->id);

        if ($isKev && $policy->failOnKevAnySeverity) {
            return true;
        }

        if (!$vuln->severity->isAtLeast($policy->failOn)) {
            return false;
        }

        if ($policy->requireFixAvailable && !$vuln->hasFixAvailable()) {
            return false;
        }

        return true;
    }

    private function formatReason(Vulnerability $vuln, ?KevCatalog $kev): string
    {
        $isKev = $kev !== null && $kev->contains($vuln->id);
        $parts = [
            $vuln->id,
            $vuln->severity->value,
            $vuln->packageName . '@' . $vuln->installedVersion,
        ];

        if ($vuln->hasFixAvailable()) {
            $parts[] = 'fix=' . $vuln->fixedVersion;
        }

        if ($isKev) {
            $parts[] = 'KEV';
        }

        return implode(' ', $parts);
    }
}
