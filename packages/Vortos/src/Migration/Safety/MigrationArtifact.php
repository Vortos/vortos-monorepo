<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Schema\MigrationPhase;

final readonly class MigrationArtifact
{
    /**
     * @param list<string> $upSql
     * @param list<string> $downSql
     */
    public function __construct(
        public string $version,
        public ?string $className,
        public ?MigrationPhase $phase,
        public array $upSql,
        public array $downSql,
        public bool $hasAllowFullTableRewrite,
    ) {}
}
