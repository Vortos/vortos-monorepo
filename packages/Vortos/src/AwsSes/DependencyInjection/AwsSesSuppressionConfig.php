<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesSuppressionConfig
{
    private string $tableName = 'aws_ses_suppression_list';
    private bool $syncOnStartup = false;
    private string $onSuppressed = 'throw';

    public function tableName(string $table): static
    {
        $this->tableName = $table;
        return $this;
    }

    public function syncOnStartup(bool $sync): static
    {
        $this->syncOnStartup = $sync;
        return $this;
    }

    /**
     * Behaviour when a suppressed recipient is detected.
     * 'throw' — throws SuppressionListException (default, safest).
     * 'strip' — removes suppressed recipients and continues send.
     */
    public function onSuppressed(string $behaviour): static
    {
        $this->onSuppressed = $behaviour;
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'table_name'      => $this->tableName,
            'sync_on_startup' => $this->syncOnStartup,
            'on_suppressed'   => $this->onSuppressed,
        ];
    }
}
