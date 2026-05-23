<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationDriftReport;

interface MigrationDriftFormatterInterface
{
    public function label(?MigrationDriftReport $report, bool $executed): string;

    /** @return array<string, mixed> */
    public function toArray(?MigrationDriftReport $report, bool $executed): array;
}
