<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\BackfillSafetyAnalyzer;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;

final class BackfillSafetyAnalyzerTest extends TestCase
{
    private function analyzer(): BackfillSafetyAnalyzer
    {
        $extractor = $this->createMock(MigrationSqlExtractorInterface::class);

        return new BackfillSafetyAnalyzer($extractor);
    }

    public function test_unbounded_update_is_blocked(): void
    {
        $findings = $this->analyzer()->analyzeStatements(['UPDATE users SET status = 1']);

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->blocked);
        self::assertStringContainsString('Unbounded UPDATE', $findings[0]->reason);
    }

    public function test_unbounded_delete_is_blocked(): void
    {
        $findings = $this->analyzer()->analyzeStatements(['DELETE FROM temp_data']);

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->blocked);
        self::assertStringContainsString('Unbounded DELETE', $findings[0]->reason);
    }

    public function test_update_with_where_is_allowed(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'UPDATE users SET status = 1 WHERE id BETWEEN 1 AND 1000 LIMIT 1000',
        ]);

        self::assertSame([], $findings);
    }

    public function test_delete_with_where_is_allowed(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'DELETE FROM temp_data WHERE created_at < NOW() - INTERVAL \'30 days\'',
        ]);

        self::assertSame([], $findings);
    }

    public function test_batched_update_with_limit_is_allowed(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'UPDATE users SET status = 1 WHERE id > 0 LIMIT 500',
        ]);

        self::assertSame([], $findings);
    }

    public function test_allow_full_table_rewrite_opt_out(): void
    {
        $findings = $this->analyzer()->analyzeStatements(
            ['UPDATE users SET email_new = email'],
            hasOptOut: true,
        );

        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->blocked);
        self::assertStringContainsString('Allowed', $findings[0]->reason);
    }

    public function test_select_is_not_flagged(): void
    {
        $findings = $this->analyzer()->analyzeStatements(['SELECT * FROM users']);

        self::assertSame([], $findings);
    }

    public function test_insert_is_not_flagged(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'INSERT INTO audit_log (event) VALUES (\'migrate\')',
        ]);

        self::assertSame([], $findings);
    }

    public function test_create_table_is_not_flagged(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'CREATE TABLE new_table (id SERIAL PRIMARY KEY)',
        ]);

        self::assertSame([], $findings);
    }

    public function test_multiple_statements_multiple_findings(): void
    {
        $findings = $this->analyzer()->analyzeStatements([
            'UPDATE users SET status = 1',
            'DELETE FROM temp',
            'SELECT 1',
        ]);

        self::assertCount(2, $findings);
        self::assertTrue($findings[0]->blocked);
        self::assertTrue($findings[1]->blocked);
    }
}
