<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleOutboxConfig
{
    private int $batchSize             = 50;
    private int $maxAttempts           = 5;
    private int $backoffBaseSeconds    = 60;
    private int $backoffCapSeconds     = 3600;
    private int $sleepSecondsWhenEmpty = 2;

    public function batchSize(int $size): static
    {
        $this->batchSize = $size;
        return $this;
    }

    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function backoffBaseSeconds(int $seconds): static
    {
        $this->backoffBaseSeconds = $seconds;
        return $this;
    }

    public function backoffCapSeconds(int $seconds): static
    {
        $this->backoffCapSeconds = $seconds;
        return $this;
    }

    public function sleepSecondsWhenEmpty(int $seconds): static
    {
        $this->sleepSecondsWhenEmpty = $seconds;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'batch_size'               => $this->batchSize,
            'max_attempts'             => $this->maxAttempts,
            'backoff_base_seconds'     => $this->backoffBaseSeconds,
            'backoff_cap_seconds'      => $this->backoffCapSeconds,
            'sleep_seconds_when_empty' => $this->sleepSecondsWhenEmpty,
        ];
    }
}
