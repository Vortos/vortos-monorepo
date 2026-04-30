<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Generator;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Generator\MigrationClassGenerator;

final class MigrationClassGeneratorTest extends TestCase
{
    private MigrationClassGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MigrationClassGenerator();
    }

    // ── generateFromSql ─────────────────────────────────────────────────────

    public function test_generated_class_contains_correct_namespace(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Create outbox table',
            'CREATE TABLE outbox (id UUID PRIMARY KEY)',
        );

        $this->assertStringContainsString('namespace App\\Migrations;', $output);
    }

    public function test_generated_class_has_correct_class_name(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Create outbox table',
            'CREATE TABLE outbox (id UUID PRIMARY KEY)',
        );

        $this->assertStringContainsString('final class Version20260430000001', $output);
    }

    public function test_generated_class_extends_abstract_migration(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Create outbox table',
            'CREATE TABLE outbox (id UUID PRIMARY KEY)',
        );

        $this->assertStringContainsString('extends AbstractMigration', $output);
    }

    public function test_generated_class_embeds_sql_in_up_method(): void
    {
        $sql    = 'CREATE TABLE outbox (id UUID PRIMARY KEY)';
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Create outbox',
            $sql,
        );

        $this->assertStringContainsString('$this->addSql(', $output);
        $this->assertStringContainsString('CREATE TABLE outbox', $output);
    }

    public function test_generated_class_has_irreversible_down(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Create outbox table',
            'CREATE TABLE outbox (id UUID PRIMARY KEY)',
        );

        $this->assertStringContainsString('throwIrreversibleMigrationException', $output);
    }

    public function test_multi_statement_sql_produces_multiple_add_sql_calls(): void
    {
        $sql = "CREATE TABLE outbox (id UUID PRIMARY KEY);\nCREATE INDEX idx_outbox ON outbox (id)";

        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Outbox with index',
            $sql,
        );

        $this->assertSame(2, substr_count($output, '$this->addSql('));
    }

    public function test_trailing_semicolon_does_not_produce_empty_statement(): void
    {
        $sql    = 'CREATE TABLE outbox (id UUID PRIMARY KEY);';
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Outbox',
            $sql,
        );

        $this->assertSame(1, substr_count($output, '$this->addSql('));
    }

    public function test_description_is_embedded_in_get_description(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'My custom migration description',
            'SELECT 1',
        );

        $this->assertStringContainsString("return 'My custom migration description'", $output);
    }

    public function test_single_quotes_in_sql_are_escaped(): void
    {
        $sql    = "INSERT INTO config (key, value) VALUES ('mode', 'active')";
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Seed config',
            $sql,
        );

        $this->assertStringNotContainsString("('mode', 'active')", $output);
        $this->assertStringContainsString("\\'mode\\'", $output);
    }

    public function test_output_has_strict_types_declaration(): void
    {
        $output = $this->generator->generateFromSql(
            'Version20260430000001',
            'App\\Migrations',
            'Test',
            'SELECT 1',
        );

        $this->assertStringContainsString("declare(strict_types=1);", $output);
    }

    // ── generateEmpty ────────────────────────────────────────────────────────

    public function test_empty_migration_has_manual_down_stub(): void
    {
        $output = $this->generator->generateEmpty(
            'Version20260430143022',
            'App\\Migrations',
            'Create orders table',
        );

        $this->assertStringNotContainsString('throwIrreversibleMigrationException', $output);
        $this->assertStringContainsString('public function down(', $output);
    }

    public function test_empty_migration_has_guidance_comment_in_up(): void
    {
        $output = $this->generator->generateEmpty(
            'Version20260430143022',
            'App\\Migrations',
            'Create orders table',
        );

        $this->assertStringContainsString('addSql()', $output);
    }

    // ── buildClassName ───────────────────────────────────────────────────────

    public function test_build_class_name_without_sequence(): void
    {
        $this->assertSame('Version20260430143022', $this->generator->buildClassName('20260430143022'));
    }

    public function test_build_class_name_with_sequence_pads_to_two_digits(): void
    {
        $this->assertSame('Version2026043014302201', $this->generator->buildClassName('20260430143022', 1));
        $this->assertSame('Version2026043014302299', $this->generator->buildClassName('20260430143022', 99));
    }

    // ── descriptionFromFilename ───────────────────────────────────────────────

    public function test_description_strips_numeric_prefix(): void
    {
        $this->assertSame('Vortos outbox', $this->generator->descriptionFromFilename('001_vortos_outbox.sql'));
    }

    public function test_description_converts_underscores_to_spaces(): void
    {
        $this->assertSame('Create users table', $this->generator->descriptionFromFilename('002_create_users_table.sql'));
    }

    public function test_description_title_cases_first_word(): void
    {
        $desc = $this->generator->descriptionFromFilename('001_outbox.sql');
        $this->assertSame('O', substr($desc, 0, 1));
    }

    public function test_description_handles_filename_without_prefix(): void
    {
        $desc = $this->generator->descriptionFromFilename('outbox.sql');
        $this->assertNotEmpty($desc);
    }
}
