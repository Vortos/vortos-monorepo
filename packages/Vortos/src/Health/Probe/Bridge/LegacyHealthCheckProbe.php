<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Bridge;

use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class LegacyHealthCheckProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly HealthCheckInterface $delegate,
        public readonly string $driverKey,
        private readonly bool $critical,
    ) {}

    public function name(): string
    {
        return $this->delegate->name();
    }

    public function kind(): ProbeKind
    {
        return ProbeKind::Readiness;
    }

    public function check(): ProbeResult
    {
        $result = $this->delegate->check();

        if ($result->healthy) {
            return ProbeResult::pass($this->name(), $this->kind(), $result->latencyMs);
        }

        if (!$this->critical) {
            return ProbeResult::warn(
                $this->name(),
                $this->kind(),
                $result->latencyMs,
                $result->errorCode ?? 'legacy_check_degraded',
            );
        }

        return ProbeResult::fail(
            $this->name(),
            $this->kind(),
            $result->latencyMs,
            $result->errorCode ?? 'legacy_check_failed',
        );
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            HealthCapability::DependencyCheck->value => true,
            HealthCapability::BoundedLatency->value => true,
            HealthCapability::ReadOnly->value => true,
            HealthCapability::ProcessLocal->value => false,
        ]);
    }
}
