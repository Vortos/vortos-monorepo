<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

final class AwsSesAuditLogConfig
{
    private bool $enabled = true;
    private string $tableName = 'aws_ses_audit_log';

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

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled'    => $this->enabled,
            'table_name' => $this->tableName,
        ];
    }
}
