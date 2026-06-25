<?php

declare(strict_types=1);

namespace Vortos\Deploy\Contract;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;
use Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('flag-gate')]
final class FlagGateReadiness implements ContractReadinessInterface
{
    public function __construct(
        private readonly FlagGateMetadataReaderInterface $flagGateReader,
        private readonly ?GuardrailMetricSourceInterface $metricSource = null,
        private readonly int $windowSeconds = 3600,
        private readonly float $maxAllowedExposureRate = 0.0,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'deploy-count-soak' => false,
            'time-window-soak' => false,
            'exposure-telemetry' => true,
        ]);
    }

    public function isCleared(string $migrationId, EnvironmentName $env): bool
    {
        $spec = $this->flagGateReader->flagGateFor($migrationId);
        if ($spec === null) {
            // No #[GatedByFlag] declaration — nothing to assert telemetry against. Fail closed.
            return false;
        }

        if ($this->metricSource === null) {
            // FeatureFlags exposure telemetry not wired (package not installed, or no
            // metric backend configured). Fail closed rather than trusting a timer.
            return false;
        }

        $rate = $this->metricSource->query(new GuardrailMetricQuery(
            metricKind: GuardrailMetricKind::ExposureRateDrop,
            flagName: $spec->flagName,
            environment: $env->value,
            windowSeconds: $this->windowSeconds,
            variant: $spec->oldVariant,
        ));

        if ($rate === null) {
            // Unreachable/unknown metric backend. Unlike a guardrail (where null never
            // triggers an action), this gates a destructive migration — unknown must
            // mean "not proven safe", never "assume safe".
            return false;
        }

        return $rate <= $this->maxAllowedExposureRate;
    }

    public function reason(string $migrationId): string
    {
        return 'Flag-gate readiness: waiting for exposure telemetry to confirm zero old-code references.';
    }
}
