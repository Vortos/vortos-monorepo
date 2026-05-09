<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

final class ConsumerDefaultsConfig
{
    private int $idempotencyTtl = 86400;

    /** Dedup window in seconds. Per-consumer idempotencyTtl() overrides this. */
    public function idempotencyTtl(int $seconds): static
    {
        $this->idempotencyTtl = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'idempotency_ttl' => $this->idempotencyTtl,
        ];
    }
}
