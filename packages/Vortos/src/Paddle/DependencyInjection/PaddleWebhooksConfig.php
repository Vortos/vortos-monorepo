<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

final class PaddleWebhooksConfig
{
    private bool   $enabled            = true;
    private string $inboxTable         = 'paddle_webhook_inbox';
    private int    $inboxBatchSize     = 50;
    private int    $inboxMaxAttempts   = 5;
    private int    $backoffBaseSeconds = 60;
    private int    $backoffCapSeconds  = 3600;

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    /** Inbox table name (tenant/app prefix is applied by the extension). */
    public function inboxTable(string $table): static
    {
        $this->inboxTable = $table;
        return $this;
    }

    /** Rows the inbox worker claims per batch. */
    public function inboxBatchSize(int $batchSize): static
    {
        $this->inboxBatchSize = $batchSize;
        return $this;
    }

    /** Attempts before a row is dead-lettered (revive via paddle:inbox:replay). */
    public function inboxMaxAttempts(int $maxAttempts): static
    {
        $this->inboxMaxAttempts = $maxAttempts;
        return $this;
    }

    /** Exponential backoff base delay between retries, in seconds. */
    public function backoffBaseSeconds(int $seconds): static
    {
        $this->backoffBaseSeconds = $seconds;
        return $this;
    }

    /** Upper bound for the retry backoff delay, in seconds. */
    public function backoffCapSeconds(int $seconds): static
    {
        $this->backoffCapSeconds = $seconds;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'enabled'              => $this->enabled,
            'inbox_table'          => $this->inboxTable,
            'inbox_batch_size'     => $this->inboxBatchSize,
            'inbox_max_attempts'   => $this->inboxMaxAttempts,
            'backoff_base_seconds' => $this->backoffBaseSeconds,
            'backoff_cap_seconds'  => $this->backoffCapSeconds,
        ];
    }
}
