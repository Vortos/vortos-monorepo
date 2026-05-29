<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleWebhooksConfig
{
    private bool   $enabled              = true;
    private string $idempotencyTable     = 'paddle_webhook_idempotency';
    private int    $idempotencyTtlSeconds = 259200; // 72 hours

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function idempotencyTable(string $table): static
    {
        $this->idempotencyTable = $table;
        return $this;
    }

    public function idempotencyTtlSeconds(int $seconds): static
    {
        $this->idempotencyTtlSeconds = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'enabled'                 => $this->enabled,
            'idempotency_table'       => $this->idempotencyTable,
            'idempotency_ttl_seconds' => $this->idempotencyTtlSeconds,
        ];
    }
}
