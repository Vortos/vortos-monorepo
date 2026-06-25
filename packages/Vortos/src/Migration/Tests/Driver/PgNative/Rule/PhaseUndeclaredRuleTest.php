<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\PhaseUndeclaredRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class PhaseUndeclaredRuleTest extends TestCase
{
    private PhaseUndeclaredRule $rule;

    protected function setUp(): void
    {
        $this->rule = new PhaseUndeclaredRule();
    }

    public function test_flags_ddl_with_no_phase(): void
    {
        $sql = "CREATE INDEX idx ON users (email)";
        $artifact = new MigrationArtifact('Test', null, null, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
    }

    public function test_clean_ddl_with_phase_declared(): void
    {
        $sql = "CREATE INDEX idx ON users (email)";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_non_ddl_statements(): void
    {
        $sql = "INSERT INTO users (email) VALUES ('test@example.com')";
        $artifact = new MigrationArtifact('Test', null, null, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_only_fires_on_first_statement(): void
    {
        $sql = "CREATE INDEX idx ON users (email)";
        $artifact = new MigrationArtifact('Test', null, null, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 1)));

        $this->assertCount(0, $diags);
    }
}
