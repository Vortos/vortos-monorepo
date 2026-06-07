<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\MigrationSqlParser;

final class MigrationSqlParserTest extends TestCase
{
    private MigrationSqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MigrationSqlParser();
    }

    // --- parseTables ---

    public function test_parses_create_table_without_if_not_exists(): void
    {
        $tables = $this->parser->parseTables('CREATE TABLE users (id INT)');

        $this->assertCount(1, $tables);
        $this->assertSame('users', $tables[0]['name']);
        $this->assertFalse($tables[0]['ifNotExists']);
    }

    public function test_parses_create_table_with_if_not_exists(): void
    {
        $tables = $this->parser->parseTables('CREATE TABLE IF NOT EXISTS orders (id INT)');

        $this->assertCount(1, $tables);
        $this->assertSame('orders', $tables[0]['name']);
        $this->assertTrue($tables[0]['ifNotExists']);
    }

    public function test_parses_multiple_create_table_statements(): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS vortos_outbox (id UUID PRIMARY KEY);
            CREATE TABLE sessions (token VARCHAR(255));
        SQL;

        $tables = $this->parser->parseTables($sql);

        $this->assertCount(2, $tables);
        $this->assertSame('vortos_outbox', $tables[0]['name']);
        $this->assertTrue($tables[0]['ifNotExists']);
        $this->assertSame('sessions', $tables[1]['name']);
        $this->assertFalse($tables[1]['ifNotExists']);
    }

    public function test_returns_empty_array_when_no_create_table(): void
    {
        $this->assertSame([], $this->parser->parseTables('SELECT 1'));
    }

    public function test_table_name_is_lowercased(): void
    {
        $tables = $this->parser->parseTables('CREATE TABLE Users (id INT)');

        $this->assertSame('users', $tables[0]['name']);
    }

    // --- parseIndexes ---

    public function test_parses_create_index_without_if_not_exists(): void
    {
        $indexes = $this->parser->parseIndexes('CREATE INDEX idx_users_email ON users (email)');

        $this->assertCount(1, $indexes);
        $this->assertSame('idx_users_email', $indexes[0]['name']);
        $this->assertSame('users', $indexes[0]['table']);
        $this->assertFalse($indexes[0]['ifNotExists']);
    }

    public function test_parses_create_unique_index_with_if_not_exists(): void
    {
        $indexes = $this->parser->parseIndexes(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_email ON users (email)',
        );

        $this->assertCount(1, $indexes);
        $this->assertSame('idx_email', $indexes[0]['name']);
        $this->assertSame('users', $indexes[0]['table']);
        $this->assertTrue($indexes[0]['ifNotExists']);
    }

    public function test_returns_empty_array_when_no_create_index(): void
    {
        $this->assertSame([], $this->parser->parseIndexes('CREATE TABLE foo (id INT)'));
    }

    // --- parseAddColumns ---

    public function test_parses_alter_table_add_column(): void
    {
        $cols = $this->parser->parseAddColumns(
            'ALTER TABLE users ADD COLUMN verified BOOLEAN NOT NULL DEFAULT FALSE',
        );

        $this->assertCount(1, $cols);
        $this->assertSame('users', $cols[0]['table']);
        $this->assertSame('verified', $cols[0]['column']);
    }

    public function test_parses_alter_table_add_without_column_keyword(): void
    {
        $cols = $this->parser->parseAddColumns('ALTER TABLE orders ADD status VARCHAR(20)');

        $this->assertCount(1, $cols);
        $this->assertSame('orders', $cols[0]['table']);
        $this->assertSame('status', $cols[0]['column']);
    }

    public function test_returns_empty_array_when_no_add_column(): void
    {
        $this->assertSame([], $this->parser->parseAddColumns('CREATE TABLE foo (id INT)'));
    }

    public function test_column_and_table_names_are_lowercased(): void
    {
        $cols = $this->parser->parseAddColumns('ALTER TABLE Users ADD COLUMN EmailAddress TEXT');

        $this->assertSame('users', $cols[0]['table']);
        $this->assertSame('emailaddress', $cols[0]['column']);
    }
}
