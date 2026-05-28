<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesCircuitBreakerConfig
{
    private int $failureThreshold = 5;
    private int $resetTimeoutSeconds = 60;

    /**
     * Consecutive SES failures before the circuit opens and routes to the fallback region.
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
            'failure_threshold'      => $this->failureThreshold,
            'reset_timeout_seconds'  => $this->resetTimeoutSeconds,
        ];
    }
}
