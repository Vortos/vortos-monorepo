<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Alerts;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Security\SupplyChain\Model\Vulnerability\Vulnerability;
use Vortos\Security\SupplyChain\Model\Vulnerability\VulnerabilityReport;
use Vortos\Security\SupplyChain\Service\RuntimeCveWatcher;

final class RuntimeCveAlertSource
{
    public function __construct(
        private readonly RuntimeCveWatcher $watcher,
        private readonly AlertDispatcherInterface $dispatcher,
    ) {}

    /**
     * @return list<DispatchResult>
     */
    public function tick(
        ?VulnerabilityReport $previous,
        VulnerabilityReport $current,
        string $env,
        DateTimeImmutable $now,
    ): array {
        $newVulns = $this->watcher->diff($previous, $current);
        $results = [];

        foreach ($newVulns as $vuln) {
            $event = AlertEvent::scrubbed(
                ruleId: 'supply-chain.cve.' . $vuln->id,
                severity: $this->mapSeverity($vuln),
                title: sprintf('New CVE: %s in %s', $vuln->id, $vuln->packageName),
                summary: sprintf(
                    '%s %s in %s@%s%s%s',
                    $vuln->id,
                    $vuln->severity->value,
                    $vuln->packageName,
                    $vuln->installedVersion,
                    $vuln->hasFixAvailable() ? ' (fix: ' . $vuln->fixedVersion . ')' : '',
                    $vuln->kev ? ' [KEV]' : '',
                ),
                source: AlertSource::SupplyChain,
                env: $env,
                tenantId: null,
                labels: ['cve' => $vuln->id, 'package' => $vuln->packageName],
                annotations: ['severity' => $vuln->severity->value],
                links: [],
                occurredAt: $now,
            );

            $results[] = $this->dispatcher->dispatch($event);
        }

        return $results;
    }

    private function mapSeverity(Vulnerability $vuln): Severity
    {
        if ($vuln->kev || $vuln->severity === \Vortos\Security\SupplyChain\Model\Vulnerability\Severity::Critical) {
            return Severity::Critical;
        }

        if ($vuln->severity->isAtLeast(\Vortos\Security\SupplyChain\Model\Vulnerability\Severity::High)) {
            return Severity::Warning;
        }

        return Severity::Info;
    }
}
