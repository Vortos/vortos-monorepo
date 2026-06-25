<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Security\SupplyChain\Model\Vulnerability\Vulnerability;
use Vortos\Security\SupplyChain\Model\Vulnerability\VulnerabilityReport;

final class RuntimeCveWatcher
{
    /**
     * Returns only NEW advisories not present in the previous report.
     *
     * @return list<Vulnerability>
     */
    public function diff(?VulnerabilityReport $previous, VulnerabilityReport $current): array
    {
        if ($previous === null) {
            return $current->vulnerabilities;
        }

        $previousIds = [];
        foreach ($previous->vulnerabilities as $vuln) {
            $previousIds[$vuln->id] = true;
        }

        $newAdvisories = [];
        foreach ($current->vulnerabilities as $vuln) {
            if (!isset($previousIds[$vuln->id])) {
                $newAdvisories[] = $vuln;
            }
        }

        return $newAdvisories;
    }
}
