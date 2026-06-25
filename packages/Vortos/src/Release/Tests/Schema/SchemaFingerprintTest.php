<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Schema\FingerprintRelation;
use Vortos\Release\Schema\SchemaFingerprint;

final class SchemaFingerprintTest extends TestCase
{
    // ── Construction & normalization ──

    public function test_empty_set(): void
    {
        $fp = SchemaFingerprint::empty();
        $this->assertTrue($fp->isEmpty());
        $this->assertSame(0, $fp->count());
        $this->assertSame([], $fp->migrationIds);
    }

    public function test_deduplicates_ids(): void
    {
        $fp = new SchemaFingerprint(['a', 'b', 'a', 'c', 'b']);
        $this->assertSame(['a', 'b', 'c'], $fp->migrationIds);
        $this->assertSame(3, $fp->count());
    }

    public function test_sorts_ids(): void
    {
        $fp = new SchemaFingerprint(['z', 'a', 'm']);
        $this->assertSame(['a', 'm', 'z'], $fp->migrationIds);
    }

    public function test_order_independence(): void
    {
        $a = new SchemaFingerprint(['m3', 'm1', 'm2']);
        $b = new SchemaFingerprint(['m1', 'm2', 'm3']);
        $c = new SchemaFingerprint(['m2', 'm3', 'm1']);

        $this->assertSame($a->hash, $b->hash);
        $this->assertSame($b->hash, $c->hash);
    }

    public function test_hash_format(): void
    {
        $fp = new SchemaFingerprint(['m1']);
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $fp->hash);
    }

    public function test_single_element(): void
    {
        $fp = new SchemaFingerprint(['only_one']);
        $this->assertFalse($fp->isEmpty());
        $this->assertSame(1, $fp->count());
        $this->assertTrue($fp->contains('only_one'));
        $this->assertFalse($fp->contains('other'));
    }

    // ── Pinned test vector ──

    public function test_pinned_vector_stability(): void
    {
        $fp = new SchemaFingerprint(['migration_001', 'migration_002', 'migration_003']);

        $expected = 'sha256:' . hash('sha256', "migration_001\nmigration_002\nmigration_003");
        $this->assertSame($expected, $fp->hash, 'Hash must remain stable across releases.');
    }

    public function test_pinned_vector_shuffled_produces_same_hash(): void
    {
        $canonical = new SchemaFingerprint(['migration_001', 'migration_002', 'migration_003']);
        $shuffled = new SchemaFingerprint(['migration_003', 'migration_001', 'migration_002']);

        $this->assertSame($canonical->hash, $shuffled->hash);
    }

    // ── contains ──

    public function test_contains(): void
    {
        $fp = new SchemaFingerprint(['a', 'b', 'c']);
        $this->assertTrue($fp->contains('a'));
        $this->assertTrue($fp->contains('c'));
        $this->assertFalse($fp->contains('d'));
    }

    // ── equals ──

    public function test_equals_same_set(): void
    {
        $a = new SchemaFingerprint(['x', 'y']);
        $b = new SchemaFingerprint(['y', 'x']);
        $this->assertTrue($a->equals($b));
    }

    public function test_not_equals_different_set(): void
    {
        $a = new SchemaFingerprint(['x', 'y']);
        $b = new SchemaFingerprint(['x', 'z']);
        $this->assertFalse($a->equals($b));
    }

    public function test_empty_equals_empty(): void
    {
        $this->assertTrue(SchemaFingerprint::empty()->equals(SchemaFingerprint::empty()));
    }

    // ── Subset / superset algebra ──

    public function test_empty_is_subset_of_anything(): void
    {
        $empty = SchemaFingerprint::empty();
        $non = new SchemaFingerprint(['a']);
        $this->assertTrue($empty->isSubsetOf($non));
        $this->assertTrue($empty->isSubsetOf($empty));
    }

    public function test_proper_subset(): void
    {
        $sub = new SchemaFingerprint(['a', 'b']);
        $sup = new SchemaFingerprint(['a', 'b', 'c']);
        $this->assertTrue($sub->isSubsetOf($sup));
        $this->assertFalse($sup->isSubsetOf($sub));
    }

    public function test_equal_sets_are_both_subset_and_superset(): void
    {
        $a = new SchemaFingerprint(['a', 'b']);
        $b = new SchemaFingerprint(['b', 'a']);
        $this->assertTrue($a->isSubsetOf($b));
        $this->assertTrue($a->isSupersetOf($b));
    }

    public function test_disjoint_not_subset(): void
    {
        $a = new SchemaFingerprint(['x']);
        $b = new SchemaFingerprint(['y']);
        $this->assertFalse($a->isSubsetOf($b));
        $this->assertFalse($b->isSubsetOf($a));
    }

    // ── relationTo — exhaustive algebra table ──

    #[DataProvider('relationProvider')]
    public function test_relation_to(array $left, array $right, FingerprintRelation $expected): void
    {
        $a = new SchemaFingerprint($left);
        $b = new SchemaFingerprint($right);
        $this->assertSame($expected, $a->relationTo($b));
    }

    /** @return iterable<string, array{list<string>, list<string>, FingerprintRelation}> */
    public static function relationProvider(): iterable
    {
        yield 'equal (both non-empty)' => [['a', 'b'], ['b', 'a'], FingerprintRelation::Equal];
        yield 'equal (both empty)' => [[], [], FingerprintRelation::Equal];
        yield 'equal (single element)' => [['x'], ['x'], FingerprintRelation::Equal];
        yield 'subset' => [['a'], ['a', 'b', 'c'], FingerprintRelation::Subset];
        yield 'subset (empty vs non-empty)' => [[], ['a'], FingerprintRelation::Subset];
        yield 'superset' => [['a', 'b', 'c'], ['a', 'b'], FingerprintRelation::Superset];
        yield 'superset (non-empty vs empty)' => [['a'], [], FingerprintRelation::Superset];
        yield 'overlapping' => [['a', 'b'], ['b', 'c'], FingerprintRelation::Overlapping];
        yield 'overlapping (large)' => [['a', 'b', 'c', 'd'], ['c', 'd', 'e', 'f'], FingerprintRelation::Overlapping];
        yield 'disjoint' => [['a', 'b'], ['c', 'd'], FingerprintRelation::Disjoint];
        yield 'disjoint (single elements)' => [['x'], ['y'], FingerprintRelation::Disjoint];
    }

    // ── missingFrom ──

    public function test_missing_from_none(): void
    {
        $target = new SchemaFingerprint(['a', 'b']);
        $applied = new SchemaFingerprint(['a', 'b', 'c']);
        $this->assertSame([], $target->missingFrom($applied));
    }

    public function test_missing_from_some(): void
    {
        $target = new SchemaFingerprint(['a', 'b', 'c']);
        $applied = new SchemaFingerprint(['a']);
        $this->assertSame(['b', 'c'], $target->missingFrom($applied));
    }

    public function test_missing_from_all(): void
    {
        $target = new SchemaFingerprint(['x', 'y']);
        $applied = new SchemaFingerprint(['a', 'b']);
        $this->assertSame(['x', 'y'], $target->missingFrom($applied));
    }

    public function test_missing_from_empty_applied(): void
    {
        $target = new SchemaFingerprint(['a']);
        $this->assertSame(['a'], $target->missingFrom(SchemaFingerprint::empty()));
    }

    public function test_empty_target_missing_from_anything(): void
    {
        $this->assertSame([], SchemaFingerprint::empty()->missingFrom(new SchemaFingerprint(['a'])));
    }

    // ── Serialization round-trip ──

    public function test_to_array_from_array_round_trip(): void
    {
        $original = new SchemaFingerprint(['m3', 'm1', 'm2']);
        $restored = SchemaFingerprint::fromArray($original->toArray());

        $this->assertSame($original->hash, $restored->hash);
        $this->assertSame($original->migrationIds, $restored->migrationIds);
    }

    public function test_to_array_structure(): void
    {
        $fp = new SchemaFingerprint(['b', 'a']);
        $arr = $fp->toArray();

        $this->assertArrayHasKey('hash', $arr);
        $this->assertArrayHasKey('migration_ids', $arr);
        $this->assertSame(['a', 'b'], $arr['migration_ids']);
    }
}
