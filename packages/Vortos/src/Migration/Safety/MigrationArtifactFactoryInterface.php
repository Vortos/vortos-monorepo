<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Schema\MigrationPhase;

interface MigrationArtifactFactoryInterface
{
    public function fromClass(string $className): MigrationArtifact;

    /**
     * @param list<string> $upSql
     * @param list<string> $downSql
     */
    public function fromRawSql(
        string $version,
        array $upSql,
        array $downSql = [],
        ?MigrationPhase $phase = null,
        bool $hasAllowFullTableRewrite = false,
    ): MigrationArtifact;
}
