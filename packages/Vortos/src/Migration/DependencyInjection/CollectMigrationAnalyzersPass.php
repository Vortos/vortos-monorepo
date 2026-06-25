<?php

declare(strict_types=1);

namespace Vortos\Migration\DependencyInjection;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectMigrationAnalyzersPass extends CollectDriversCompilerPass
{
    public function __construct()
    {
        parent::__construct(
            'vortos.migration.safety_analyzer',
            'vortos.migration.safety_analyzer_locator',
            'migration-safety',
        );
    }
}
