<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\LockTimeoutMissingRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class LockTimeoutMissingRuleTest extends TestCase
{
    public function test_flags_ddl_without_lock_timeout_when_enforcer_not_configured(): void
    {
        $rule = new LockTimeoutMissingRule(enforcerLockTimeoutMs: 0);
        $sql = "CREATE INDEX idx ON users (email)";
        $diags = iterator_to_array($rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
    }

    public function test_clean_when_enforcer_configured(): void
    {
        $rule = new LockTimeoutMissingRule(enforcerLockTimeoutMs: 3000);
        $sql = "CREATE INDEX idx ON users (email)";
        $diags = iterator_to_array($rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_clean_when_migration_has_explicit_set_lock_timeout(): void
    {
        $rule = new LockTimeoutMissingRule(enforcerLockTimeoutMs: 0);
        $artifact = $this->artifact(['SET lock_timeout = 3000', 'CREATE INDEX idx ON users (email)']);
        $sql = "CREATE INDEX idx ON users (email)";
        $diags = iterator_to_array($rule->evaluate(
            $artifact,
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_dml_statements(): void
    {
        $rule = new LockTimeoutMissingRule(enforcerLockTimeoutMs: 0);
        $sql = "INSERT INTO users (email) VALUES ('test@example.com')";
        $diags = iterator_to_array($rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_only_fires_on_first_statement(): void
    {
        $rule = new LockTimeoutMissingRule(enforcerLockTimeoutMs: 0);
        $sql = "CREATE INDEX idx ON users (email)";
        $diags = iterator_to_array($rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 1),
        ));

        $this->assertCount(0, $diags);
    }

    /** @param list<string> $sql */
    private function artifact(array $sql): MigrationArtifact
    {
        return new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, $sql, [], false);
    }
}
