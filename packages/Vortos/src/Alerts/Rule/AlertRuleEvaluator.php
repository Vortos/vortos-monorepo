<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

use DateTimeImmutable;
use InvalidArgumentException;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Rule\Condition\CertExpiryCondition;
use Vortos\Alerts\Rule\Condition\ResourceCondition;
use Vortos\Alerts\Rule\Condition\SloBurnCondition;
use Vortos\Alerts\Rule\Condition\ThresholdCondition;
use Vortos\Alerts\Rule\Sample\BackupFailedSample;
use Vortos\Alerts\Rule\Sample\BurnRateSample;
use Vortos\Alerts\Rule\Sample\CertExpirySample;
use Vortos\Alerts\Rule\Sample\HealthProbeSample;
use Vortos\Alerts\Rule\Sample\ResourceSample;
use Vortos\Alerts\Rule\Sample\SampleInterface;
use Vortos\Alerts\Rule\Sample\ThresholdSample;
use Vortos\Alerts\Severity;

/**
 * Pure: given a validated rule + an observed sample, returns a fully-formed
 * {@see AlertEvent} or null (§3.2). `slo_burn` reuses
 * {@see \Vortos\Observability\Slo\BurnRatePolicy::isPageWorthy()} directly via
 * {@see SloBurnCondition} — single source of truth with Block 16.
 */
final class AlertRuleEvaluator
{
    /** @param array<string, string> $extraLabels merged into the rule's declared labels for the fingerprint */
    public function evaluate(
        AlertRule $rule,
        SampleInterface $sample,
        string $env,
        ?string $tenantId,
        DateTimeImmutable $occurredAt,
        array $extraLabels = [],
    ): ?AlertEvent {
        $outcome = $this->fire($rule, $sample);
        if ($outcome === null) {
            return null;
        }
        [$severity, $title, $summary, $source] = $outcome;

        return AlertEvent::scrubbed(
            ruleId: $rule->id,
            severity: $severity,
            title: $title,
            summary: $summary,
            source: $source,
            env: $env,
            tenantId: $tenantId,
            labels: [...$rule->labels, ...$extraLabels],
            annotations: [],
            links: [],
            occurredAt: $occurredAt,
        );
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fire(AlertRule $rule, SampleInterface $sample): ?array
    {
        return match ($rule->kind) {
            AlertRuleKind::ErrorRate => $this->fireThreshold($rule, $sample, 'error rate', AlertSource::Slo),
            AlertRuleKind::P95Latency => $this->fireThreshold($rule, $sample, 'p95 latency', AlertSource::Slo),
            AlertRuleKind::QueueLag => $this->fireThreshold($rule, $sample, 'queue lag', AlertSource::Queue),
            AlertRuleKind::SloBurn => $this->fireSloBurn($rule, $sample),
            AlertRuleKind::HealthProbeFailing => $this->fireHealthProbe($rule, $sample),
            AlertRuleKind::ResourceExhaustion => $this->fireResource($rule, $sample),
            AlertRuleKind::CertNearExpiry => $this->fireCertExpiry($rule, $sample),
            AlertRuleKind::BackupFailed => $this->fireBackupFailed($rule, $sample),
        };
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireThreshold(AlertRule $rule, SampleInterface $sample, string $label, AlertSource $source): ?array
    {
        if (!$sample instanceof ThresholdSample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a ThresholdSample.', $rule->id));
        }
        if (!$rule->condition instanceof ThresholdCondition) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a ThresholdCondition.', $rule->id));
        }
        if (!$rule->condition->fires($sample->value)) {
            return null;
        }

        return [
            $rule->severity,
            sprintf('%s threshold breached: %s', $label, $rule->id),
            sprintf('Observed %s %.4f vs threshold %.4f.', $label, $sample->value, $rule->condition->value),
            $source,
        ];
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireSloBurn(AlertRule $rule, SampleInterface $sample): ?array
    {
        if (!$sample instanceof BurnRateSample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a BurnRateSample.', $rule->id));
        }
        if (!$rule->condition instanceof SloBurnCondition) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a SloBurnCondition.', $rule->id));
        }
        if (!$rule->condition->fires($sample->fastBurnRate, $sample->slowBurnRate)) {
            return null;
        }

        return [
            $rule->severity,
            sprintf('SLO error-budget burn: %s', $rule->sloRef ?? $rule->id),
            sprintf('Burn rate fast=%.2f slow=%.2f.', $sample->fastBurnRate, $sample->slowBurnRate),
            AlertSource::Slo,
        ];
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireHealthProbe(AlertRule $rule, SampleInterface $sample): ?array
    {
        if (!$sample instanceof HealthProbeSample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a HealthProbeSample.', $rule->id));
        }
        if (!$sample->failing) {
            return null;
        }

        return [
            $rule->severity,
            sprintf('Health probe failing: %s', $sample->probeName),
            $sample->detail !== '' ? $sample->detail : sprintf('Probe "%s" is failing.', $sample->probeName),
            AlertSource::Health,
        ];
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireResource(AlertRule $rule, SampleInterface $sample): ?array
    {
        if (!$sample instanceof ResourceSample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a ResourceSample.', $rule->id));
        }
        if (!$rule->condition instanceof ResourceCondition) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a ResourceCondition.', $rule->id));
        }

        $severity = $rule->condition->severityFor($sample->usedPct);
        if ($severity === null) {
            return null;
        }

        return [
            $severity,
            sprintf('Resource exhaustion: %s', $sample->resourceName),
            sprintf('%s at %.1f%% utilization.', $sample->resourceName, $sample->usedPct),
            AlertSource::Capacity,
        ];
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireCertExpiry(AlertRule $rule, SampleInterface $sample): ?array
    {
        if (!$sample instanceof CertExpirySample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a CertExpirySample.', $rule->id));
        }
        if (!$rule->condition instanceof CertExpiryCondition) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a CertExpiryCondition.', $rule->id));
        }

        $severity = $rule->condition->severityFor($sample->daysUntilExpiry);
        if ($severity === null) {
            return null;
        }

        return [
            $severity,
            sprintf('Certificate near expiry: %s', $sample->subject),
            sprintf('Certificate for "%s" expires in %d day(s).', $sample->subject, $sample->daysUntilExpiry),
            AlertSource::Cert,
        ];
    }

    /** @return array{0:Severity,1:string,2:string,3:AlertSource}|null */
    private function fireBackupFailed(AlertRule $rule, SampleInterface $sample): ?array
    {
        if (!$sample instanceof BackupFailedSample) {
            throw new InvalidArgumentException(sprintf('Rule "%s" requires a BackupFailedSample.', $rule->id));
        }
        if (!$sample->failed) {
            return null;
        }

        return [
            $rule->severity,
            'Backup failed',
            $sample->detail !== '' ? $sample->detail : 'A backup or restore-drill has failed.',
            AlertSource::Backup,
        ];
    }
}
