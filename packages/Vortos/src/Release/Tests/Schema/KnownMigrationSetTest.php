<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\SchemaFingerprint;

final class KnownMigrationSetTest extends TestCase
{
    public function test_empty(): void
    {
        $set = KnownMigrationSet::empty();
        $this->assertSame([], $set->ids);
    }

    public function test_deduplicates_and_sorts(): void
    {
        $set = new KnownMigrationSet(['c', 'a', 'b', 'a']);
        $this->assertSame(['a', 'b', 'c'], $set->ids);
    }

    public function test_contains(): void
    {
        $set = new KnownMigrationSet(['m1', 'm2']);
        $this->assertTrue($set->contains('m1'));
        $this->assertFalse($set->contains('m3'));
    }

    public function test_unknowns_in_returns_unknown_ids(): void
    {
        $set = new KnownMigrationSet(['m1', 'm2']);
        $fp = new SchemaFingerprint(['m1', 'm2', 'rogue']);

        $this->assertSame(['rogue'], $set->unknownsIn($fp));
    }

    public function test_unknowns_in_returns_empty_when_all_known(): void
    {
        $set = new KnownMigrationSet(['m1', 'm2', 'm3']);
        $fp = new SchemaFingerprint(['m1', 'm2']);

        $this->assertSame([], $set->unknownsIn($fp));
    }

    public function test_unknowns_in_empty_fingerprint(): void
    {
        $set = new KnownMigrationSet(['m1']);
        $this->assertSame([], $set->unknownsIn(SchemaFingerprint::empty()));
    }

    public function test_unknowns_in_empty_known_set(): void
    {
        $set = KnownMigrationSet::empty();
        $fp = new SchemaFingerprint(['m1']);
        $this->assertSame(['m1'], $set->unknownsIn($fp));
    }
}
