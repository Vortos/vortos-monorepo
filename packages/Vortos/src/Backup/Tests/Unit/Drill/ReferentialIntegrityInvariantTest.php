<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Drill\Check\ReferentialIntegrityInvariant;

/**
 * The SQL the check runs. Verified end to end against PostgreSQL 18 on 2026-11-27 (composite key with a
 * null member, an orphan in a non-public schema, a read-only role, and a database with no keys).
 */
final class ReferentialIntegrityInvariantTest extends TestCase
{
    public function test_a_composite_key_is_checked_as_one_key_with_its_columns_paired_in_order(): void
    {
        $sql = ReferentialIntegrityInvariant::orphanCountSql([
            'child'          => 'registration_submissions',
            'parent'         => 'team_registrations',
            'match_type'     => 's',
            'child_columns'  => '["team_registration_id","organization_id"]',
            'parent_columns' => '["id","organization_id"]',
        ]);

        self::assertSame(
            'SELECT count(*) FROM registration_submissions t WHERE (t.team_registration_id IS NOT NULL AND t.organization_id IS NOT NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM team_registrations r WHERE r.id = t.team_registration_id AND r.organization_id = t.organization_id))',
            $sql,
        );
        self::assertStringNotContainsString('r.id = t.organization_id', $sql, 'the cross-product pairing that invented 281 orphans');
    }

    public function test_match_full_also_counts_a_partly_null_key(): void
    {
        $sql = ReferentialIntegrityInvariant::orphanCountSql([
            'child'          => 'a',
            'parent'         => 'b',
            'match_type'     => 'f',
            'child_columns'  => '["x","y"]',
            'parent_columns' => '["p","q"]',
        ]);

        self::assertStringContainsString('((t.x IS NOT NULL OR t.y IS NOT NULL) AND NOT (t.x IS NOT NULL AND t.y IS NOT NULL))', $sql);
    }

    public function test_unpaired_columns_are_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ReferentialIntegrityInvariant::orphanCountSql([
            'child' => 'a', 'parent' => 'b', 'match_type' => 's',
            'child_columns' => '["x","y"]', 'parent_columns' => '["p"]',
        ]);
    }

    public function test_keys_come_from_pg_constraint_in_every_schema(): void
    {
        $sql = ReferentialIntegrityInvariant::foreignKeysSql();

        self::assertStringContainsString('FROM pg_constraint', $sql);
        self::assertStringContainsString('unnest(c.conkey, c.confkey) WITH ORDINALITY', $sql);
        self::assertStringNotContainsString('information_schema.table_constraints', $sql, 'hidden from a read-only drill role');
        self::assertStringNotContainsString("nspname = 'public'", $sql);
    }

    public function test_an_unreachable_database_fails(): void
    {
        $result = (new ReferentialIntegrityInvariant())->check(['host' => '127.0.0.1', 'port' => 1, 'dbname' => 'x']);

        self::assertFalse($result->passed);
    }
}
