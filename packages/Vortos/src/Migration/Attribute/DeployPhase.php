<?php

declare(strict_types=1);

namespace Vortos\Migration\Attribute;

use Vortos\Migration\Schema\MigrationPhase;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DeployPhase
{
    public function __construct(
        public MigrationPhase $phase,
    ) {}
}
