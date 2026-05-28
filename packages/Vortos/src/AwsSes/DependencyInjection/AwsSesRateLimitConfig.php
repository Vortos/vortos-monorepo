<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesRateLimitConfig
{
    private int $maxSendRate = 14;
    private int $burst = 14;
    private int $waitTimeoutMs = 5000;

    /**
     * Maximum emails per second. Match your SES account sending rate from the AWS console.
     */
    public function maxSendRate(int $perSecond): static
    {
        $this->maxSendRate = $perSecond;
        return $this;
    }

    /**
     * Token bucket burst capacity. Defaults to match maxSendRate.
     */
    public function burst(int $tokens): static
    {
        $this->burst = $tokens;
        return $this;
    }

    /**
     * Milliseconds to wait for a token before throwing RateLimitExceededException.
     */
    public function waitTimeoutMs(int $ms): static
    {
        $this->waitTimeoutMs = $ms;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'max_send_rate'  => $this->maxSendRate,
            'burst'          => $this->burst,
            'wait_timeout_ms'=> $this->waitTimeoutMs,
        ];
    }
}
