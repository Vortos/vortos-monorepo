<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesOutboxConfig
{
    private bool $enabled = true;
    private string $tableName = 'aws_ses_outbox';
    private int $batchSize = 50;
    private int $sleepSecondsWhenEmpty = 2;
    private int $maxDeliveryAttempts = 5;
    private string $retryStrategy = 'exponential';
    private int $backoffBaseSeconds = 30;
    private int $backoffCapSeconds = 3600;
    private int $staleMessageTimeoutSeconds = 300;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function tableName(string $table): static
    {
        $this->tableName = $table;
        return $this;
    }

    public function batchSize(int $size): static
    {
        $this->batchSize = $size;
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

    public function retryStrategy(string $strategy): static
    {
        $this->retryStrategy = $strategy;
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

    public function staleMessageTimeoutSeconds(int $seconds): static
    {
        $this->staleMessageTimeoutSeconds = $seconds;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled'                       => $this->enabled,
            'table_name'                    => $this->tableName,
            'batch_size'                    => $this->batchSize,
            'sleep_seconds_when_empty'      => $this->sleepSecondsWhenEmpty,
            'max_delivery_attempts'         => $this->maxDeliveryAttempts,
            'retry_strategy'                => $this->retryStrategy,
            'backoff_base_seconds'          => $this->backoffBaseSeconds,
            'backoff_cap_seconds'           => $this->backoffCapSeconds,
            'stale_message_timeout_seconds' => $this->staleMessageTimeoutSeconds,
        ];
    }
}
