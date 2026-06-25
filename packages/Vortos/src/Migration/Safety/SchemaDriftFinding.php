<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class SchemaDriftFinding
{
    public function __construct(
        public string $module,
        public bool $hasDrift,
        public bool $unreachable,
        public string $detail,
    ) {}
}
