<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\RollbackDecision;
use Vortos\Release\Schema\RollbackInvariant;
use Vortos\Release\Schema\RollbackRefusalReason;
use Vortos\Release\Schema\SchemaFingerprint;

final class RollbackInvariantTest extends TestCase
{
    // ── Legal rollbacks ──

    public function test_legal_when_target_equals_applied(): void
    {
        $fp = new SchemaFingerprint(['m1', 'm2']);
        $known = new KnownMigrationSet(['m1', 'm2']);

        $decision = RollbackInvariant::evaluate($fp, $fp, $known);

        $this->assertTrue($decision->legal);
        $this->assertSame(RollbackRefusalReason::Legal, $decision->reason);
        $this->assertSame([], $decision->offendingMigrations);
    }

    public function test_legal_when_target_is_proper_subset(): void
    {
        $target = new SchemaFingerprint(['m1']);
        $applied = new SchemaFingerprint(['m1', 'm2']);
        $known = new KnownMigrationSet(['m1', 'm2']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertTrue($decision->legal);
    }

    public function test_legal_when_both_empty(): void
    {
        $decision = RollbackInvariant::evaluate(
            SchemaFingerprint::empty(),
            SchemaFingerprint::empty(),
            KnownMigrationSet::empty(),
        );

        $this->assertTrue($decision->legal);
    }

    public function test_legal_when_target_empty_applied_has_migrations(): void
    {
        $target = SchemaFingerprint::empty();
        $applied = new SchemaFingerprint(['m1']);
        $known = new KnownMigrationSet(['m1']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertTrue($decision->legal);
    }

    // ── Refused: target not subset ──

    public function test_refused_when_target_not_subset_of_applied(): void
    {
        $target = new SchemaFingerprint(['m1', 'm2', 'm3']);
        $applied = new SchemaFingerprint(['m1', 'm2']);
        $known = new KnownMigrationSet(['m1', 'm2', 'm3']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(RollbackRefusalReason::TargetNotSubset, $decision->reason);
        $this->assertSame(['m3'], $decision->offendingMigrations);
    }

    public function test_refused_when_target_is_superset(): void
    {
        $target = new SchemaFingerprint(['m1', 'm2', 'm3']);
        $applied = new SchemaFingerprint(['m1']);
        $known = new KnownMigrationSet(['m1', 'm2', 'm3']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(['m2', 'm3'], $decision->offendingMigrations);
    }

    public function test_refused_when_disjoint(): void
    {
        $target = new SchemaFingerprint(['x', 'y']);
        $applied = new SchemaFingerprint(['a', 'b']);
        $known = new KnownMigrationSet(['a', 'b', 'x', 'y']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(RollbackRefusalReason::TargetNotSubset, $decision->reason);
        $this->assertSame(['x', 'y'], $decision->offendingMigrations);
    }

    public function test_refused_overlapping_but_not_subset(): void
    {
        $target = new SchemaFingerprint(['m1', 'm2', 'm3']);
        $applied = new SchemaFingerprint(['m1', 'm2', 'm4']);
        $known = new KnownMigrationSet(['m1', 'm2', 'm3', 'm4']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(['m3'], $decision->offendingMigrations);
    }

    // ── Refused: unknown applied migration (fail-closed) ──

    public function test_refused_unknown_applied_migration(): void
    {
        $target = new SchemaFingerprint(['m1']);
        $applied = new SchemaFingerprint(['m1', 'hotfix_42']);
        $known = new KnownMigrationSet(['m1']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(RollbackRefusalReason::UnknownAppliedMigration, $decision->reason);
        $this->assertSame(['hotfix_42'], $decision->offendingMigrations);
    }

    public function test_unknown_takes_priority_over_not_subset(): void
    {
        $target = new SchemaFingerprint(['m1', 'm3']);
        $applied = new SchemaFingerprint(['m1', 'rogue']);
        $known = new KnownMigrationSet(['m1', 'm3']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertFalse($decision->legal);
        $this->assertSame(RollbackRefusalReason::UnknownAppliedMigration, $decision->reason);
        $this->assertSame(['rogue'], $decision->offendingMigrations);
    }

    public function test_multiple_unknown_migrations(): void
    {
        $target = new SchemaFingerprint(['m1']);
        $applied = new SchemaFingerprint(['m1', 'rogue_a', 'rogue_b']);
        $known = new KnownMigrationSet(['m1']);

        $decision = RollbackInvariant::evaluate($target, $applied, $known);

        $this->assertSame(['rogue_a', 'rogue_b'], $decision->offendingMigrations);
    }

    // ── Explain ──

    public function test_explain_legal(): void
    {
        $decision = RollbackDecision::allowed();
        $this->assertStringContainsString('safe', $decision->explain());
    }

    public function test_explain_target_not_subset(): void
    {
        $decision = RollbackDecision::targetNotSubset(['m3', 'm4']);
        $explanation = $decision->explain();

        $this->assertStringContainsString('m3', $explanation);
        $this->assertStringContainsString('m4', $explanation);
        $this->assertStringContainsString('roll forward', $explanation);
        $this->assertStringContainsString('compensating expand', $explanation);
    }

    public function test_explain_unknown_applied(): void
    {
        $decision = RollbackDecision::unknownAppliedMigration(['hotfix_42']);
        $explanation = $decision->explain();

        $this->assertStringContainsString('hotfix_42', $explanation);
        $this->assertStringContainsString('manual hotfix', $explanation);
        $this->assertStringContainsString('record a manifest', $explanation);
    }
}
