<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Service\MigrationDriftFormatter;

final class MigrationDriftFormatterTest extends TestCase
{
    public function test_formats_clean_report(): void
    {
        $formatter = new MigrationDriftFormatter();
        $report = new MigrationDriftReport(MigrationDriftReport::Clean);

        $this->assertSame('Clean', $formatter->label($report, executed: false));
        $this->assertFalse($formatter->toArray($report, executed: false)['blocks_migration']);
    }

    public function test_formats_compatible_existing_as_blocking_drift(): void
    {
        $formatter = new MigrationDriftFormatter();
        $report = new MigrationDriftReport(MigrationDriftReport::CompatibleExisting, existingTables: ['orders']);

        $this->assertSame('Drift: compatible existing schema', $formatter->label($report, executed: false));
        $this->assertTrue($formatter->toArray($report, executed: false)['blocks_migration']);
        $this->assertSame(['orders'], $formatter->toArray($report, executed: false)['existing_tables']);
    }

    public function test_formats_executed_migration_as_tracked(): void
    {
        $formatter = new MigrationDriftFormatter();

        $this->assertSame('Tracked', $formatter->label(null, executed: true));
        $this->assertSame('tracked', $formatter->toArray(null, executed: true)['status']);
    }
}
