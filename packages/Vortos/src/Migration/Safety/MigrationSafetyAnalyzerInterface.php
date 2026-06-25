<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\OpsKit\Driver\DriverInterface;

interface MigrationSafetyAnalyzerInterface extends DriverInterface
{
    public function analyze(MigrationArtifact $migration, ?TargetSchemaSnapshot $target): SafetyResult;

    public function engine(): string;
}
