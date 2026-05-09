<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

final class OutboxConfig
{
    private string $table = 'vortos_outbox';
    private int $maxAttempts = 5;
    private int $backoffBase = 30;
    private int $backoffCap = 3600;

    public function table(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    /** Initial backoff in seconds. Doubles each attempt. */
    public function backoffBase(int $seconds): static
    {
        $this->backoffBase = $seconds;
        return $this;
    }

    /** Maximum backoff in seconds — backoff is capped at this value. */
    public function backoffCap(int $seconds): static
    {
        $this->backoffCap = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'table'        => $this->table,
            'max_attempts' => $this->maxAttempts,
            'backoff_base' => $this->backoffBase,
            'backoff_cap'  => $this->backoffCap,
        ];
    }
}
