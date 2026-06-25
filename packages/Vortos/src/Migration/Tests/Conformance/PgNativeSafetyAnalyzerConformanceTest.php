<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Conformance;

use Vortos\Migration\Driver\PgNative\PgNativeSafetyAnalyzer;
use Vortos\Migration\Driver\PgNative\Rule\BlockingAlterRule;
use Vortos\Migration\Driver\PgNative\Rule\ConcurrentInTransactionRule;
use Vortos\Migration\Driver\PgNative\Rule\FullTableRewriteRule;
use Vortos\Migration\Driver\PgNative\Rule\LockTimeoutMissingRule;
use Vortos\Migration\Driver\PgNative\Rule\NonConcurrentIndexRule;
use Vortos\Migration\Driver\PgNative\Rule\NotNullNoDefaultRule;
use Vortos\Migration\Driver\PgNative\Rule\PhaseMismatchRule;
use Vortos\Migration\Driver\PgNative\Rule\PhaseUndeclaredRule;
use Vortos\Migration\Driver\PgNative\Rule\VolatileDefaultRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\Rule\SafetyRuleSet;
use Vortos\Migration\Schema\MigrationPhase;

final class PgNativeSafetyAnalyzerConformanceTest extends MigrationSafetyAnalyzerConformanceTestCase
{
    protected function createAnalyzer(): MigrationSafetyAnalyzerInterface
    {
        $ruleSet = new SafetyRuleSet();
        $ruleSet->add(new NonConcurrentIndexRule());
        $ruleSet->add(new ConcurrentInTransactionRule());
        $ruleSet->add(new VolatileDefaultRule());
        $ruleSet->add(new NotNullNoDefaultRule());
        $ruleSet->add(new BlockingAlterRule());
        $ruleSet->add(new LockTimeoutMissingRule(enforcerLockTimeoutMs: 3000));
        $ruleSet->add(new FullTableRewriteRule());
        $ruleSet->add(new PhaseMismatchRule());
        $ruleSet->add(new PhaseUndeclaredRule());

        return new PgNativeSafetyAnalyzer($ruleSet);
    }

    protected function expectedKey(): string
    {
        return 'pg-native';
    }

    public function test_unsafe_migration_produces_at_least_one_error(): void
    {
        $analyzer = $this->createAnalyzer();
        $artifact = new MigrationArtifact(
            version: 'UnsafeMigration',
            className: null,
            phase: MigrationPhase::Expand,
            upSql: ['CREATE INDEX idx_users_email ON users (email)'],
            downSql: [],
            hasAllowFullTableRewrite: false,
        );

        $result = $analyzer->analyze($artifact, null);

        $this->assertTrue($result->hasErrors(), 'Non-CONCURRENTLY index must produce at least one Error.');
    }

    public function test_dialect_constraint_is_postgres(): void
    {
        $descriptor = $this->createAnalyzer()->capabilities();
        $this->assertSame('postgres', $descriptor->constraint('dialect'));
    }

    public function test_understands_expand_contract(): void
    {
        $descriptor = $this->createAnalyzer()->capabilities();
        $this->assertTrue($descriptor->supports('understands_expand_contract'));
    }
}
