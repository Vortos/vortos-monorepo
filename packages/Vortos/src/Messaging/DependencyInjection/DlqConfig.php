<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

final class DlqConfig
{
    private string $table = 'vortos_failed_messages';

    public function table(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
        ];
    }
}
