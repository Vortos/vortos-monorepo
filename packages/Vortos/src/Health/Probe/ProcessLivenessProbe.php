<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('process-liveness')]
final class ProcessLivenessProbe implements HealthProbeInterface
{
    private const MEMORY_HEADROOM_BYTES = 4 * 1024 * 1024;

    public function name(): string
    {
        return 'process-liveness';
    }

    public function kind(): ProbeKind
    {
        return ProbeKind::Liveness;
    }

    public function check(): ProbeResult
    {
        $start = hrtime(true);

        $memoryLimit = $this->memoryLimitBytes();
        $memoryUsage = memory_get_usage(true);

        if ($memoryLimit > 0 && ($memoryLimit - $memoryUsage) < self::MEMORY_HEADROOM_BYTES) {
            $latencyMs = (hrtime(true) - $start) / 1_000_000;

            return ProbeResult::fail(
                $this->name(),
                $this->kind(),
                $latencyMs,
                'memory_exhaustion_imminent',
                [
                    'usage_bytes' => $memoryUsage,
                    'limit_bytes' => $memoryLimit,
                ],
            );
        }

        $latencyMs = (hrtime(true) - $start) / 1_000_000;

        return ProbeResult::pass($this->name(), $this->kind(), $latencyMs);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            HealthCapability::DependencyCheck->value => false,
            HealthCapability::BoundedLatency->value => true,
            HealthCapability::ReadOnly->value => true,
            HealthCapability::ProcessLocal->value => true,
        ]);
    }

    private function memoryLimitBytes(): int
    {
        // ini_get() returns false only for an UNKNOWN directive. memory_limit is a core PHP ini
        // setting, so it is always defined and the return is always a string — checking for false
        // was dead code, which is what the analyser was pointing at. '-1' (no limit) and '' still
        // mean "no limit to report", and both are handled.
        $limit = ini_get('memory_limit');
        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $value = (int) $limit;
        $suffix = strtolower(substr(trim($limit), -1));

        return match ($suffix) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
