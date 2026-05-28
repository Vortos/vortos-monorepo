<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreRetryConfig
{
    private int $maxAttempts = 3;
    private int $backoffBaseMilliseconds = 100;
    private int $backoffCapMilliseconds = 2000;

    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function backoffBaseMilliseconds(int $milliseconds): static
    {
        $this->backoffBaseMilliseconds = $milliseconds;
        return $this;
    }

    public function backoffCapMilliseconds(int $milliseconds): static
    {
        $this->backoffCapMilliseconds = $milliseconds;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'max_attempts'              => $this->maxAttempts,
            'backoff_base_milliseconds' => $this->backoffBaseMilliseconds,
            'backoff_cap_milliseconds'  => $this->backoffCapMilliseconds,
        ];
    }
}
