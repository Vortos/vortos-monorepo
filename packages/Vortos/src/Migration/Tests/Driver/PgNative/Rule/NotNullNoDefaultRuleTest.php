<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\NotNullNoDefaultRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Schema\MigrationPhase;

final class NotNullNoDefaultRuleTest extends TestCase
{
    private NotNullNoDefaultRule $rule;

    protected function setUp(): void
    {
        $this->rule = new NotNullNoDefaultRule();
    }

    public function test_flags_add_column_not_null_without_default(): void
    {
        $diags = $this->evaluate('ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL');

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertSame('users', $diags[0]->table);
    }

    public function test_allows_add_column_not_null_with_default(): void
    {
        $this->assertSame([], $this->evaluate(
            "ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'",
        ));
    }

    /**
     * The regression this rule shipped with.
     *
     * BlockingAlterRule refuses `ALTER COLUMN … SET NOT NULL` and prescribes a NOT VALID
     * CHECK instead. This rule then rejected that CHECK: its ADD pattern bound to the
     * CONSTRAINT keyword, and the `NOT NULL` inside the CHECK *body* satisfied the second
     * guard — so the prescribed remedy was refused by the rule prescribing it, and this
     * rule's own remediation pointed back at SET NOT NULL. Neither had an opt-out, so
     * there was no legal way to make an existing column non-null.
     */
    public function test_does_not_flag_a_not_valid_check_constraint(): void
    {
        $this->assertSame([], $this->evaluate(
            'ALTER TABLE notifications ADD CONSTRAINT chk_ref CHECK (recipient_ref IS NOT NULL) NOT VALID',
        ));
    }

    /** Even validated immediately, ADD CONSTRAINT is BlockingAlterRule's business, not this one's. */
    public function test_does_not_flag_a_check_constraint_without_not_valid(): void
    {
        $this->assertSame([], $this->evaluate(
            'ALTER TABLE notifications ADD CONSTRAINT chk_ref CHECK (recipient_ref IS NOT NULL)',
        ));
    }

    public function test_does_not_flag_a_foreign_key_constraint(): void
    {
        $this->assertSame([], $this->evaluate(
            'ALTER TABLE orders ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) NOT VALID',
        ));
    }

    /** The remediation must not send the reader to a statement another rule always refuses. */
    public function test_remediation_does_not_recommend_set_not_null(): void
    {
        $diags = $this->evaluate('ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL');

        $this->assertCount(1, $diags);
        $this->assertStringNotContainsStringIgnoringCase('SET NOT NULL', (string) $diags[0]->remediation);
        $this->assertStringContainsString('NOT VALID', (string) $diags[0]->remediation);
    }

    /** @return list<\Vortos\Migration\Safety\SafetyDiagnostic> */
    private function evaluate(string $sql): array
    {
        return array_values(iterator_to_array($this->rule->evaluate(
            new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, [$sql], [], false),
            null,
            new ParsedStatement($sql, 0),
        )));
    }
}
