<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Safety;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\DestructiveSqlDetector;

final class DestructiveSqlDetectorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function destructiveStatements(): iterable
    {
        yield 'drop table'      => ['DROP TABLE accounts'];
        yield 'drop column'     => ['ALTER TABLE accounts DROP COLUMN legacy'];
        yield 'drop index'      => ['DROP INDEX idx_accounts_email'];
        yield 'drop constraint' => ['ALTER TABLE accounts DROP CONSTRAINT fk_x'];
        yield 'alter type'      => ['ALTER TABLE accounts ALTER COLUMN amount TYPE BIGINT'];
        yield 'set not null'    => ['ALTER TABLE accounts ALTER COLUMN email SET NOT NULL'];
        yield 'rename'          => ['ALTER TABLE accounts RENAME COLUMN a TO b'];
        yield 'drop default'    => ['ALTER TABLE accounts ALTER COLUMN status DROP DEFAULT'];
        yield 'truncate'        => ['TRUNCATE accounts'];
    }

    /** @return iterable<string, array{string}> */
    public static function safeStatements(): iterable
    {
        yield 'create table'  => ['CREATE TABLE accounts (id UUID PRIMARY KEY)'];
        yield 'add column'    => ['ALTER TABLE accounts ADD COLUMN email VARCHAR(255)'];
        yield 'add if exists' => ['ALTER TABLE accounts ADD IF NOT EXISTS email VARCHAR(255)'];
        yield 'create index'  => ['CREATE INDEX idx_x ON accounts (email)'];
        yield 'insert'        => ["INSERT INTO accounts (id) VALUES ('x')"];
    }

    #[DataProvider('destructiveStatements')]
    public function test_detects_destructive(string $sql): void
    {
        self::assertTrue((new DestructiveSqlDetector())->isDestructive($sql), $sql);
        self::assertNotNull((new DestructiveSqlDetector())->firstMatch($sql));
    }

    #[DataProvider('safeStatements')]
    public function test_passes_safe(string $sql): void
    {
        self::assertFalse((new DestructiveSqlDetector())->isDestructive($sql), $sql);
        self::assertNull((new DestructiveSqlDetector())->firstMatch($sql));
    }

    public function test_any_destructive_over_list(): void
    {
        $detector = new DestructiveSqlDetector();

        self::assertTrue($detector->anyDestructive([
            'ALTER TABLE accounts ADD COLUMN email VARCHAR(255)',
            'ALTER TABLE accounts DROP COLUMN legacy',
        ]));
        self::assertFalse($detector->anyDestructive([
            'CREATE TABLE x (id INT)',
            'ALTER TABLE x ADD COLUMN y INT',
        ]));
        self::assertFalse($detector->anyDestructive([]));
    }

    // ── Guarding against an operation is not performing it ────────────────────────────────────

    public function test_a_trigger_that_forbids_truncate_is_not_destructive(): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_no_truncate()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only: TRUNCATE is prohibited';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_no_truncate();
            SQL;

        self::assertFalse((new DestructiveSqlDetector())->isDestructive($sql));
    }

    public function test_an_actual_truncate_is_still_destructive(): void
    {
        self::assertTrue((new DestructiveSqlDetector())->isDestructive('TRUNCATE audit_events'));
    }

    public function test_a_destructive_keyword_inside_a_string_literal_is_ignored(): void
    {
        $sql = "INSERT INTO notes (body) VALUES ('remember to DROP TABLE legacy_users someday')";

        self::assertFalse((new DestructiveSqlDetector())->isDestructive($sql));
    }

    public function test_a_destructive_keyword_inside_a_comment_is_ignored(): void
    {
        self::assertFalse((new DestructiveSqlDetector())->isDestructive(
            "-- follow-up: DROP COLUMN legacy_flag once backfilled\nCREATE INDEX i ON t (c)",
        ));
    }

    public function test_a_real_drop_beside_a_comment_still_counts(): void
    {
        self::assertTrue((new DestructiveSqlDetector())->isDestructive(
            "-- cleaning up\nALTER TABLE t DROP COLUMN legacy_flag",
        ));
    }

    /** A BEFORE UPDATE trigger is protective; an ALTER COLUMN TYPE beside it is not. */
    public function test_stripping_the_event_clause_does_not_hide_a_real_change(): void
    {
        $sql = "CREATE TRIGGER g BEFORE UPDATE ON t FOR EACH ROW EXECUTE FUNCTION f();\n"
             . "ALTER TABLE t ALTER COLUMN c TYPE bigint";

        self::assertSame('ALTER COLUMN TYPE', (new DestructiveSqlDetector())->firstMatch($sql));
    }
}
