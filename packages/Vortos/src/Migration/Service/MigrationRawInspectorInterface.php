<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

interface MigrationRawInspectorInterface
{
    public function tableExistsRaw(string $table): bool;

    public function columnExistsRaw(string $table, string $column): bool;
}
