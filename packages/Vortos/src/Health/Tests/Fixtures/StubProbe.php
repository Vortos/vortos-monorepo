<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Fixtures;

use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class StubProbe implements HealthProbeInterface
{
    private ?ProbeResult $fixedResult = null;
    private int $sleepMs = 0;
    private ?\Throwable $exception = null;

    public function __construct(
        private readonly string $probeName,
        private readonly ProbeKind $probeKind = ProbeKind::Readiness,
        private readonly bool $dependencyCheck = true,
    ) {}

    public static function readiness(string $name): self
    {
        return new self($name, ProbeKind::Readiness);
    }

    public static function liveness(string $name): self
    {
        return new self($name, ProbeKind::Liveness, false);
    }

    public static function startup(string $name): self
    {
        return new self($name, ProbeKind::Startup);
    }

    public function withResult(ProbeResult $result): self
    {
        $clone = clone $this;
        $clone->fixedResult = $result;

        return $clone;
    }

    public function withSleep(int $ms): self
    {
        $clone = clone $this;
        $clone->sleepMs = $ms;

        return $clone;
    }

    public function withException(\Throwable $e): self
    {
        $clone = clone $this;
        $clone->exception = $e;

        return $clone;
    }

    public function name(): string
    {
        return $this->probeName;
    }

    public function kind(): ProbeKind
    {
        return $this->probeKind;
    }

    public function check(): ProbeResult
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        if ($this->fixedResult !== null) {
            return $this->fixedResult;
        }

        return ProbeResult::pass($this->probeName, $this->probeKind, 0.1);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            HealthCapability::DependencyCheck->value => $this->dependencyCheck,
            HealthCapability::BoundedLatency->value => true,
            HealthCapability::ReadOnly->value => true,
            HealthCapability::ProcessLocal->value => !$this->dependencyCheck,
        ]);
    }
}
