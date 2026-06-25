<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Alerts;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Security\SupplyChain\Service\SecretHygieneFinding;
use Vortos\Security\SupplyChain\Service\SecretHygieneAuditor;

final class StaleSecretAlertSource
{
    public function __construct(
        private readonly SecretHygieneAuditor $auditor,
        private readonly AlertDispatcherInterface $dispatcher,
    ) {}

    /**
     * @param list<\Vortos\Security\SupplyChain\Service\SecretAuditEntry> $entries
     * @return list<DispatchResult>
     */
    public function tick(array $entries, string $env, DateTimeImmutable $now): array
    {
        $findings = $this->auditor->audit($entries, $now);
        $results = [];

        foreach ($findings as $finding) {
            $severity = $finding->kind === 'leaked' ? Severity::Critical : Severity::Warning;

            $event = AlertEvent::scrubbed(
                ruleId: 'supply-chain.secret.' . $finding->kind . '.' . $finding->secretId,
                severity: $severity,
                title: sprintf('Secret hygiene: %s (%s)', $finding->kind, $finding->secretId),
                summary: $finding->detail,
                source: AlertSource::SupplyChain,
                env: $env,
                tenantId: null,
                labels: ['secret_id' => $finding->secretId, 'kind' => $finding->kind],
                annotations: [],
                links: [],
                occurredAt: $now,
            );

            $results[] = $this->dispatcher->dispatch($event);
        }

        return $results;
    }
}
