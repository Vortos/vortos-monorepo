<?php

declare(strict_types=1);

namespace Vortos\Migration\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class GatedByFlag
{
    public function __construct(
        public string $flagName,
        public string $oldVariant = 'control',
    ) {}
}
