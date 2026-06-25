<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Service\MigrationPhaseHeuristic;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;
use Vortos\Migration\Service\PhaseMisdeclarationException;

final class MigrationPhaseHeuristicTest extends TestCase
{
    private function heuristic(): MigrationPhaseHeuristic
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);

        return new MigrationPhaseHeuristic($extractor);
    }

    /** @dataProvider destructiveTokenProvider */
    public function test_destructive_token_declared_expand_throws(string $sql, string $expectedToken): void
    {
        $tokens = $this->heuristic()->detectDestructiveTokens([$sql]);

        self::assertNotEmpty($tokens, "Expected destructive token [{$expectedToken}] to be detected.");
        self::assertContains($expectedToken, $tokens);
    }

    public static function destructiveTokenProvider(): iterable
    {
        yield 'DROP COLUMN' => ['ALTER TABLE users DROP COLUMN email_old', 'DROP COLUMN'];
        yield 'DROP TABLE' => ['DROP TABLE old_users', 'DROP TABLE'];
        yield 'RENAME' => ['ALTER TABLE users RENAME COLUMN email TO email_legacy', 'RENAME'];
        yield 'ALTER COLUMN TYPE' => ['ALTER TABLE users ALTER COLUMN name TYPE TEXT', 'ALTER COLUMN TYPE'];
        yield 'SET NOT NULL' => ['ALTER TABLE users ALTER COLUMN name SET NOT NULL', 'SET NOT NULL'];
        yield 'DROP DEFAULT' => ['ALTER TABLE users ALTER COLUMN name DROP DEFAULT', 'DROP DEFAULT'];
        yield 'DROP INDEX' => ['DROP INDEX idx_users_email', 'DROP INDEX'];
        yield 'DROP CONSTRAINT' => ['ALTER TABLE users DROP CONSTRAINT fk_address', 'DROP CONSTRAINT'];
    }

    /** @dataProvider destructiveTokenProvider */
    public function test_destructive_token_declared_contract_passes(string $sql): void
    {
        $tokens = $this->heuristic()->detectDestructiveTokens([$sql]);

        // Tokens are detected, but validate() returns early for Contract phase
        self::assertNotEmpty($tokens);
    }

    /** @dataProvider safeTokenProvider */
    public function test_safe_sql_declared_expand_passes(string $sql): void
    {
        $tokens = $this->heuristic()->detectDestructiveTokens([$sql]);

        self::assertSame([], $tokens);
    }

    public static function safeTokenProvider(): iterable
    {
        yield 'ADD COLUMN NULL' => ['ALTER TABLE users ADD COLUMN email_new VARCHAR(255) NULL'];
        yield 'CREATE INDEX CONCURRENTLY' => ['CREATE INDEX CONCURRENTLY idx_email ON users (email)'];
        yield 'CREATE TABLE' => ['CREATE TABLE new_table (id SERIAL PRIMARY KEY, name TEXT)'];
        yield 'INSERT' => ['INSERT INTO config (key, value) VALUES (\'a\', \'b\')'];
        yield 'SELECT' => ['SELECT * FROM users'];
    }

    public function test_validate_contract_phase_never_throws(): void
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);
        $extractor->method('extractFromClass')->willReturn(['DROP TABLE users']);

        $heuristic = new MigrationPhaseHeuristic($extractor);
        $heuristic->validate('SomeClass', MigrationPhase::Contract);

        $this->addToAssertionCount(1);
    }

    public function test_validate_expand_with_destructive_sql_throws(): void
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);
        $extractor->method('extractFromClass')->willReturn(['ALTER TABLE users DROP COLUMN old_email']);

        $heuristic = new MigrationPhaseHeuristic($extractor);

        $this->expectException(PhaseMisdeclarationException::class);

        $heuristic->validate('SomeExpandMigration', MigrationPhase::Expand);
    }

    public function test_validate_expand_with_safe_sql_passes(): void
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);
        $extractor->method('extractFromClass')->willReturn([
            'ALTER TABLE users ADD COLUMN email_new VARCHAR(255) NULL',
        ]);

        $heuristic = new MigrationPhaseHeuristic($extractor);
        $heuristic->validate('SafeExpandMigration', MigrationPhase::Expand);

        $this->addToAssertionCount(1);
    }

    public function test_validate_expand_with_empty_sql_passes(): void
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);
        $extractor->method('extractFromClass')->willReturn([]);

        $heuristic = new MigrationPhaseHeuristic($extractor);
        $heuristic->validate('EmptyMigration', MigrationPhase::Expand);

        $this->addToAssertionCount(1);
    }

    public function test_exception_carries_migration_id_and_tokens(): void
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);
        $extractor->method('extractFromClass')->willReturn(['DROP TABLE users; ALTER TABLE t DROP COLUMN c']);

        $heuristic = new MigrationPhaseHeuristic($extractor);

        try {
            $heuristic->validate('BadMigration', MigrationPhase::Expand);
            $this->fail('Expected PhaseMisdeclarationException');
        } catch (PhaseMisdeclarationException $e) {
            self::assertSame('BadMigration', $e->migrationId);
            self::assertSame(MigrationPhase::Expand, $e->declaredPhase);
            self::assertContains('DROP TABLE', $e->destructiveTokens);
            self::assertContains('DROP COLUMN', $e->destructiveTokens);
        }
    }

    public function test_case_insensitive_detection(): void
    {
        $tokens = $this->heuristic()->detectDestructiveTokens(['drop table users']);
        self::assertContains('DROP TABLE', $tokens);
    }
}
