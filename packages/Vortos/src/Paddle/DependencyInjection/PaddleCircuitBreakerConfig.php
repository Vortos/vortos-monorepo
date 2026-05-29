<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleCircuitBreakerConfig
{
    private bool $enabled              = true;
    private int  $failureThreshold     = 5;
    private int  $resetTimeoutSeconds  = 60;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function failureThreshold(int $failures): static
    {
        $this->failureThreshold = $failures;
        return $this;
    }

    public function resetTimeoutSeconds(int $seconds): static
    {
        $this->resetTimeoutSeconds = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'enabled'               => $this->enabled,
            'failure_threshold'     => $this->failureThreshold,
            'reset_timeout_seconds' => $this->resetTimeoutSeconds,
        ];
    }
}
