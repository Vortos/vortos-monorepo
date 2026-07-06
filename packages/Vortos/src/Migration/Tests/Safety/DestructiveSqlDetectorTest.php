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
}
