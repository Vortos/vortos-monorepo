<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreCircuitBreakerConfig
{
    private bool $enabled           = false;
    private int $failureThreshold   = 5;
    private int $resetTimeoutSeconds = 60;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Consecutive infrastructure failures before the circuit opens and requests fast-fail.
     */
    public function failureThreshold(int $failures): static
    {
        $this->failureThreshold = $failures;
        return $this;
    }

    /**
     * Seconds the circuit stays open before allowing a probe request (half-open state).
     */
    public function resetTimeoutSeconds(int $seconds): static
    {
        $this->resetTimeoutSeconds = $seconds;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled'               => $this->enabled,
            'failure_threshold'     => $this->failureThreshold,
            'reset_timeout_seconds' => $this->resetTimeoutSeconds,
        ];
    }
}
