<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

use InvalidArgumentException;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class CertExpiryProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly CertInspectorInterface $inspector,
        private readonly string $host,
        private readonly int $port = 443,
        private readonly CertExpiryThresholds $thresholds = new CertExpiryThresholds(),
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('CertExpiryProbe host must not be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('CertExpiryProbe port must be a valid TCP port (1-65535).');
        }
    }

    public function name(): string
    {
        return 'cert-expiry';
    }

    public function kind(): ProbeKind
    {
        return ProbeKind::Readiness;
    }

    public function check(): ProbeResult
    {
        $start = microtime(true);
        $result = $this->inspector->inspect($this->host, $this->port);
        $latencyMs = (microtime(true) - $start) * 1000.0;

        if ($result->isFailure()) {
            /** @var string $errorCode guaranteed non-null when isFailure() is true */
            $errorCode = $result->errorCode;

            return ProbeResult::fail($this->name(), $this->kind(), $latencyMs, $errorCode, ['host' => $this->host]);
        }

        $days = $result->daysUntilExpiry;
        $detail = ['days_until_expiry' => $days, 'host' => $this->host];
        $status = $this->thresholds->statusFor($days);

        return match ($status) {
            ProbeStatus::Pass => ProbeResult::pass($this->name(), $this->kind(), $latencyMs, $detail),
            ProbeStatus::Warn => ProbeResult::warn($this->name(), $this->kind(), $latencyMs, 'cert_near_expiry', $detail),
            ProbeStatus::Fail => ProbeResult::fail($this->name(), $this->kind(), $latencyMs, 'cert_near_expiry_critical', $detail),
        };
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
