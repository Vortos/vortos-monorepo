<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

final readonly class DrillEnvironment
{
    public function __construct(
        public string $dsn,
        public string $label,
    ) {}
}
