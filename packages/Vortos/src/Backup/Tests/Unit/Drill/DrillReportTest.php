<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Drill\InvariantResult;

final class DrillReportTest extends TestCase
{
    public function test_passed_report(): void
    {
        $report = new DrillReport(
            'drill-1', DatabaseEngine::Postgres, 'prod', 'artifact-1',
            new DateTimeImmutable(), 5000, 'passed',
            [InvariantResult::pass('row_count', '10 tables ok')],
        );

        $this->assertTrue($report->passed());
    }

    public function test_failed_report(): void
    {
        $report = new DrillReport(
            'drill-1', DatabaseEngine::Postgres, 'prod', 'artifact-1',
            new DateTimeImmutable(), 5000, 'failed',
            [InvariantResult::fail('row_count', 'users table empty')],
            'invariant failure',
        );

        $this->assertFalse($report->passed());
        $this->assertSame('invariant failure', $report->error);
    }

    public function test_to_array_serializes_invariants(): void
    {
        $report = new DrillReport(
            'drill-1', DatabaseEngine::Postgres, 'prod', 'artifact-1',
            new DateTimeImmutable('2026-06-24'), 15000, 'passed',
            [
                InvariantResult::pass('row_count', 'ok'),
                InvariantResult::fail('fk', 'orphans'),
            ],
        );

        $arr = $report->toArray();
        $this->assertSame('drill-1', $arr['id']);
        $this->assertSame('postgres', $arr['engine']);
        $this->assertSame(15000, $arr['rto_ms']);
        $this->assertCount(2, $arr['invariants']);
        $this->assertTrue($arr['invariants'][0]['passed']);
        $this->assertFalse($arr['invariants'][1]['passed']);
    }
}
