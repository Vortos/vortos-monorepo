<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Schema\RollbackDecision;
use Vortos\Release\Schema\RollbackRefusalReason;

final class RollbackDecisionTest extends TestCase
{
    public function test_allowed_factory(): void
    {
        $d = RollbackDecision::allowed();
        $this->assertTrue($d->legal);
        $this->assertSame(RollbackRefusalReason::Legal, $d->reason);
        $this->assertSame([], $d->offendingMigrations);
    }

    public function test_target_not_subset_factory(): void
    {
        $d = RollbackDecision::targetNotSubset(['m1', 'm2']);
        $this->assertFalse($d->legal);
        $this->assertSame(RollbackRefusalReason::TargetNotSubset, $d->reason);
        $this->assertSame(['m1', 'm2'], $d->offendingMigrations);
    }

    public function test_unknown_applied_migration_factory(): void
    {
        $d = RollbackDecision::unknownAppliedMigration(['rogue']);
        $this->assertFalse($d->legal);
        $this->assertSame(RollbackRefusalReason::UnknownAppliedMigration, $d->reason);
        $this->assertSame(['rogue'], $d->offendingMigrations);
    }
}
