<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\MigrationSqlExtractor;
use Vortos\Migration\Tests\Fixtures\FakeUpDownMigration;

final class MigrationSqlExtractorTest extends TestCase
{
    private MigrationSqlExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MigrationSqlExtractor();
    }

    public function test_extract_from_class_returns_only_up_sql_not_down(): void
    {
        $sql = $this->extractor->extractFromClass(FakeUpDownMigration::class);

        // Only the two up() index creations — the down() DROPs must be excluded, otherwise
        // rollback SQL gets analysed as forward SQL (e.g. a DROP tripping the Expand gate).
        $this->assertSame(
            [
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_a ON t (a)',
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_b ON t (b)',
            ],
            $sql,
        );

        foreach ($sql as $statement) {
            $this->assertStringNotContainsStringIgnoringCase('DROP INDEX', $statement);
        }
    }

    public function test_extracts_single_quoted_sql(): void
    {
        $source = <<<'PHP'
            $this->addSql('CREATE TABLE users (id INT)');
        PHP;

        $this->assertSame(['CREATE TABLE users (id INT)'], $this->extractor->extractFromSource($source));
    }

    public function test_extracts_double_quoted_sql(): void
    {
        $source = <<<'PHP'
            $this->addSql("CREATE TABLE orders (id INT)");
        PHP;

        $this->assertSame(['CREATE TABLE orders (id INT)'], $this->extractor->extractFromSource($source));
    }

    public function test_extracts_heredoc_sql(): void
    {
        $source = <<<'PHP'
            $this->addSql(<<<SQL
                CREATE TABLE sessions (id INT)
            SQL);
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('CREATE TABLE sessions', $result[0]);
    }

    public function test_extracts_nowdoc_sql(): void
    {
        $source = <<<'PHP'
            $this->addSql(<<<'SQL'
                CREATE TABLE tokens (id INT)
            SQL);
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('CREATE TABLE tokens', $result[0]);
    }

    public function test_extracts_multiple_add_sql_calls(): void
    {
        $source = <<<'PHP'
            $this->addSql('CREATE TABLE a (id INT)');
            $this->addSql('CREATE TABLE b (id INT)');
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(2, $result);
    }

    public function test_returns_empty_for_source_with_no_add_sql(): void
    {
        $this->assertSame([], $this->extractor->extractFromSource('<?php echo "hello";'));
    }

    public function test_strips_single_quote_escape_sequences(): void
    {
        $source = <<<'PHP'
            $this->addSql('INSERT INTO t VALUES (\'foo\')');
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertStringContainsString("VALUES ('foo')", $result[0]);
    }

    public function test_returns_empty_for_nonexistent_class(): void
    {
        $this->assertSame([], $this->extractor->extractFromClass('App\\Migrations\\DoesNotExist'));
    }

    public function test_extracts_mixed_heredoc_and_single_quoted(): void
    {
        $source = <<<'PHP'
            $this->addSql(<<<'SQL'
                CREATE TABLE foo (id INT)
            SQL);
            $this->addSql('CREATE INDEX idx_foo ON foo (id)');
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('CREATE TABLE foo', $result[0]);
        $this->assertStringContainsString('CREATE INDEX idx_foo', $result[1]);
    }

    /**
     * The regression this class was rewritten for. A regex that captured one string literal
     * returned only the head of a concatenated statement — valid-looking SQL missing the
     * table it acts on, which the safety analyzer then passed as harmless.
     */
    public function test_joins_a_statement_split_across_concatenated_literals(): void
    {
        $source = <<<'PHP'
            $this->addSql(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_applications_consented_version'
                . ' ON applications (consented_at_version_id)'
            );
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(1, $result);
        $this->assertSame(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_applications_consented_version'
            . ' ON applications (consented_at_version_id)',
            $result[0],
        );
    }

    public function test_joins_three_way_concatenation_with_a_partial_index_predicate(): void
    {
        $source = <<<'PHP'
            $this->addSql(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_applications_pending_reconsent'
                . " ON applications (reconsent_deadline)"
                . " WHERE consent_state = 'pending_reconsent'"
            );
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertCount(1, $result);
        $this->assertStringEndsWith("WHERE consent_state = 'pending_reconsent'", $result[0]);
    }

    /**
     * A partially recovered statement is worse than none: it reads as valid SQL while
     * omitting whatever the analyzer most needs to see, so a dynamic argument is dropped
     * rather than half-resolved.
     */
    public function test_skips_statements_that_are_not_statically_resolvable(): void
    {
        $source = <<<'PHP'
            $this->addSql('CREATE TABLE keep_me (id INT)');
            $this->addSql('ALTER TABLE t ADD COLUMN ' . $column . ' INT');
            $this->addSql(sprintf('DROP INDEX %s', $name));
        PHP;

        $result = $this->extractor->extractFromSource($source);

        $this->assertSame(['CREATE TABLE keep_me (id INT)'], $result);
    }
}
