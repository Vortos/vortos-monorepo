<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;

interface MigrationDriftDetectorInterface
{
    public function detect(ModuleMigrationDescriptor $descriptor): MigrationDriftReport;
}
