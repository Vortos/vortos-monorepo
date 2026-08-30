<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Bridge;

use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\Contract\HealthCheckKind;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Adapts a legacy {@see HealthCheckInterface} onto the probe registry.
 *
 * The kind is supplied by the caller rather than fixed here. It used to be hardcoded to Readiness,
 * which silently put EVERY bridged dependency check on the traffic-gating path — including shared
 * external ones, where a single provider blip fails the probe on every replica at once and empties
 * the load-balancer pool. See {@see HealthCheckKind} for the reasoning and
 * {@see \Vortos\Health\DependencyInjection\Compiler\BridgeLegacyHealthChecksPass} for the mapping.
 */
final class LegacyHealthCheckProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly HealthCheckInterface $delegate,
        public readonly string $driverKey,
        private readonly bool $critical,
        private readonly ProbeKind $kind = ProbeKind::Readiness,
    ) {}

    public function name(): string
    {
        return $this->delegate->name();
    }

    public function kind(): ProbeKind
    {
        return $this->kind;
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
