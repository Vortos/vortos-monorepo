<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreOutboxConfig
{
    private bool $enabled = true;
    private string $tableName = 'object_store_outbox';
    private int $batchSize = 50;
    private int $sleepSecondsWhenEmpty = 2;
    private int $maxDeliveryAttempts = 5;
    private int $backoffBaseSeconds = 30;
    private int $backoffCapSeconds = 3600;
    private int $maxInlinePayloadBytes = 1048576;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function tableName(string $tableName): static
    {
        $this->tableName = $tableName;
        return $this;
    }

    public function batchSize(int $batchSize): static
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    public function sleepSecondsWhenEmpty(int $seconds): static
    {
        $this->sleepSecondsWhenEmpty = $seconds;
        return $this;
    }

    public function maxDeliveryAttempts(int $attempts): static
    {
        $this->maxDeliveryAttempts = $attempts;
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

    public function maxInlinePayloadBytes(int $bytes): static
    {
        $this->maxInlinePayloadBytes = $bytes;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'table_name' => $this->tableName,
            'batch_size' => $this->batchSize,
            'sleep_seconds_when_empty' => $this->sleepSecondsWhenEmpty,
            'max_delivery_attempts' => $this->maxDeliveryAttempts,
            'backoff_base_seconds' => $this->backoffBaseSeconds,
            'backoff_cap_seconds' => $this->backoffCapSeconds,
            'max_inline_payload_bytes' => $this->maxInlinePayloadBytes,
        ];
    }
}
