<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Service\SchemaDiffStatementFactory;

/**
 * R7-1 / PUBLISH-1 core regression: an alter-style provider that adds columns to a table
 * created by an *earlier* provider must produce ALTER … ADD SQL when diffed against the
 * cumulative base — the exact case (backup_catalog encryption columns) that was silently
 * dropped when each provider was rendered against a fresh, empty schema.
 */
final class SchemaDiffStatementFactoryTest extends TestCase
{
    public function test_alter_provider_on_foreign_table_emits_add_column_sql(): void
    {
        $creator = $this->provider('creator', static function (Schema $schema): void {
            $t = $schema->createTable('catalog');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->setPrimaryKey(['id']);
        });

        // Alter-style: guarded exactly like the real backup encryption provider.
        $alter = $this->provider('alter', static function (Schema $schema): void {
            if ($schema->hasTable('catalog')) {
                $c = $schema->getTable('catalog');
                $c->addColumn('encryption_provider', 'string', ['length' => 32, 'notnull' => false]);
                $c->addColumn('encryption_recipient', 'string', ['length' => 64, 'notnull' => false]);
            }
        });

        $result = (new SchemaDiffStatementFactory())->statementsFor([
            ['relative' => 'a_create.php', 'provider' => $creator],
            ['relative' => 'b_alter.php', 'provider' => $alter],
        ]);

        $createSql = implode("\n", $result['a_create.php']);
        $alterSql  = implode("\n", $result['b_alter.php']);

        $this->assertStringContainsStringIgnoringCase('CREATE TABLE', $createSql);
        $this->assertStringContainsStringIgnoringCase('catalog', $createSql);

        // The delta for the alter provider must contain the ADD COLUMNs and NOT re-create the table.
        $this->assertStringContainsStringIgnoringCase('ALTER TABLE', $alterSql);
        $this->assertStringContainsStringIgnoringCase('encryption_provider', $alterSql);
        $this->assertStringContainsStringIgnoringCase('encryption_recipient', $alterSql);
        $this->assertStringNotContainsStringIgnoringCase('CREATE TABLE', $alterSql);
    }

    public function test_alter_provider_without_base_table_produces_no_phantom_sql(): void
    {
        // No earlier creator → the guard stays false → empty diff, no crash, no phantom SQL.
        $alter = $this->provider('alter', static function (Schema $schema): void {
            if ($schema->hasTable('missing')) {
                $schema->getTable('missing')->addColumn('x', 'string');
            }
        });

        $result = (new SchemaDiffStatementFactory())->statementsFor([
            ['relative' => 'b_alter.php', 'provider' => $alter],
        ]);

        $this->assertSame([], $result['b_alter.php']);
    }

    public function test_new_index_on_existing_table_is_idempotent(): void
    {
        $creator = $this->provider('creator', static function (Schema $schema): void {
            $t = $schema->createTable('widgets');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->addColumn('status', 'string', ['length' => 20]);
            $t->setPrimaryKey(['id']);
        });

        $indexer = $this->provider('indexer', static function (Schema $schema): void {
            if ($schema->hasTable('widgets')) {
                $schema->getTable('widgets')->addIndex(['status'], 'idx_widgets_status');
            }
        });

        $result = (new SchemaDiffStatementFactory())->statementsFor([
            ['relative' => 'a.php', 'provider' => $creator],
            ['relative' => 'b.php', 'provider' => $indexer],
        ]);

        $sql = implode("\n", $result['b.php']);
        $this->assertStringContainsStringIgnoringCase('CREATE INDEX IF NOT EXISTS', $sql);
        $this->assertStringContainsStringIgnoringCase('idx_widgets_status', $sql);
    }

    public function test_add_column_is_rewritten_idempotent_but_constraints_are_not(): void
    {
        $creator = $this->provider('creator', static function (Schema $schema): void {
            $t = $schema->createTable('accounts');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->setPrimaryKey(['id']);
        });

        $alter = $this->provider('alter', static function (Schema $schema): void {
            if ($schema->hasTable('accounts')) {
                $schema->getTable('accounts')->addColumn('email', 'string', ['length' => 255, 'notnull' => false]);
            }
        });

        $result = (new SchemaDiffStatementFactory())->statementsFor([
            ['relative' => 'a.php', 'provider' => $creator],
            ['relative' => 'b.php', 'provider' => $alter],
        ]);

        $sql = implode("\n", $result['b.php']);
        $this->assertMatchesRegularExpression('/ADD (?:COLUMN )?IF NOT EXISTS email/i', $sql);
        $this->assertStringNotContainsStringIgnoringCase('ADD IF NOT EXISTS CONSTRAINT', $sql);
        $this->assertStringNotContainsStringIgnoringCase('ADD IF NOT EXISTS PRIMARY', $sql);
    }

    public function test_create_table_provider_still_emits_create(): void
    {
        $creator = $this->provider('creator', static function (Schema $schema): void {
            $t = $schema->createTable('plain');
            $t->addColumn('id', 'string', ['length' => 36]);
            $t->setPrimaryKey(['id']);
        });

        $result = (new SchemaDiffStatementFactory())->statementsFor([
            ['relative' => 'a.php', 'provider' => $creator],
        ]);

        $sql = implode("\n", $result['a.php']);
        $this->assertStringContainsStringIgnoringCase('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringContainsStringIgnoringCase('plain', $sql);
    }

    private function provider(string $id, \Closure $define): ModuleSchemaProviderInterface
    {
        return new class ($id, $define) implements ModuleSchemaProviderInterface {
            public function __construct(private string $id, private \Closure $define) {}

            public function module(): string { return 'Test'; }
            public function id(): string { return $this->id; }
            public function description(): string { return 'test ' . $this->id; }
            public function define(Schema $schema): void { ($this->define)($schema); }
            public function ownership(): MigrationOwnership { return new MigrationOwnership([], []); }
        };
    }
}
