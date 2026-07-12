<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\NonIdempotentConcurrentRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class NonIdempotentConcurrentRuleTest extends TestCase
{
    private NonIdempotentConcurrentRule $rule;

    protected function setUp(): void
    {
        $this->rule = new NonIdempotentConcurrentRule();
    }

    public function test_id(): void
    {
        $this->assertSame('pg.index.non-idempotent-concurrent', $this->rule->id());
    }

    public function test_default_severity_is_error(): void
    {
        $this->assertSame(Severity::Error, $this->rule->defaultSeverity());
    }

    public function test_flags_concurrent_index_without_if_not_exists(): void
    {
        $sql = 'CREATE INDEX CONCURRENTLY idx_users_email ON users (email)';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame('pg.index.non-idempotent-concurrent', $diags[0]->ruleId);
        $this->assertSame('users', $diags[0]->table);
        $this->assertSame('AllowNonIdempotentConcurrent', $diags[0]->optOutAttribute);
    }

    public function test_flags_concurrent_unique_index_without_if_not_exists(): void
    {
        $sql = 'CREATE UNIQUE INDEX CONCURRENTLY idx_users_email ON users (email)';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_clean_when_if_not_exists_present(): void
    {
        $sql = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_email ON users (email)';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_non_concurrent_index(): void
    {
        // A plain CREATE INDEX is the concern of pg.index.non-concurrent, not this rule.
        $sql = 'CREATE INDEX idx_users_email ON users (email)';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_opt_out_attribute_suppresses_diagnostic(): void
    {
        $sql = 'CREATE INDEX CONCURRENTLY idx_users_email ON users (email)';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql], hasOptOut: true),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    /** @param list<string> $sql */
    private function artifact(array $sql, bool $hasOptOut = false): MigrationArtifact
    {
        return new MigrationArtifact(
            version: 'TestMigration',
            className: null,
            phase: MigrationPhase::Expand,
            upSql: $sql,
            downSql: [],
            hasAllowFullTableRewrite: false,
            hasAllowNonIdempotentConcurrent: $hasOptOut,
        );
    }
}
