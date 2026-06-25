<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Deploy;

use Throwable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Deploy\Audit\DeployAuditSinkInterface;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Domain\Event\EventEnvelope;

/**
 * Implements the Block 16 {@see DeployAuditSinkInterface} seam Deploy declares (§3.7):
 * `DeployFailed` → `Critical`, `DeployRefused` → `Warning`. Registered only when
 * `vortos-deploy` is installed (class-existence guarded). Best-effort from the
 * deploy's point of view — never throws.
 */
final class DeployAuditAlertSink implements DeployAuditSinkInterface
{
    public function __construct(
        private readonly AlertDispatcherInterface $dispatcher,
    ) {}

    public function handle(EventEnvelope $envelope): void
    {
        try {
            $event = $envelope->payload;

            $alert = match (true) {
                $event instanceof DeployFailed => $this->fromDeployFailed($event, $envelope),
                $event instanceof DeployRefused => $this->fromDeployRefused($event, $envelope),
                default => null,
            };

            if ($alert !== null) {
                $this->dispatcher->dispatch($alert);
            }
        } catch (Throwable) {
            // Best-effort: never fail or block the deploy.
        }
    }

    private function fromDeployFailed(DeployFailed $event, EventEnvelope $envelope): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'deploy.failed',
            severity: Severity::Critical,
            title: sprintf('Deploy failed: %s', $event->env),
            summary: sprintf('%s: %s', $event->errorClass, $event->errorMessage),
            source: AlertSource::Deploy,
            env: $event->env,
            tenantId: null,
            labels: ['build_id' => $event->buildId, 'git_sha' => $event->gitSha],
            annotations: [],
            links: [],
            occurredAt: $envelope->occurredAt,
        );
    }

    private function fromDeployRefused(DeployRefused $event, EventEnvelope $envelope): AlertEvent
    {
        return AlertEvent::scrubbed(
            ruleId: 'deploy.refused',
            severity: Severity::Warning,
            title: sprintf('Deploy refused: %s', $event->env),
            summary: $event->reason ?? sprintf('Failed checks: %s', implode(', ', $event->failedCheckIds)),
            source: AlertSource::Deploy,
            env: $event->env,
            tenantId: null,
            labels: ['build_id' => $event->buildId, 'git_sha' => $event->gitSha],
            annotations: [],
            links: [],
            occurredAt: $envelope->occurredAt,
        );
    }
}
