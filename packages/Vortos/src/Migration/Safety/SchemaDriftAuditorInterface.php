<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

interface SchemaDriftAuditorInterface
{
    /** @return list<SchemaDriftFinding> */
    public function audit(): array;

    public function hasDrift(): bool;
}
