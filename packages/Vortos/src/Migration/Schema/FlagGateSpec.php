<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

final readonly class FlagGateSpec
{
    public function __construct(
        public string $flagName,
        public string $oldVariant,
    ) {}
}
