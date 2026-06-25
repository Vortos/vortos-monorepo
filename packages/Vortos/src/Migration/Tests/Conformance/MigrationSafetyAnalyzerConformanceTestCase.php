<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Conformance;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class MigrationSafetyAnalyzerConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createAnalyzer(): MigrationSafetyAnalyzerInterface;

    protected function createDriver(): DriverInterface
    {
        return $this->createAnalyzer();
    }

    final public function test_analyze_is_pure_for_fixed_input(): void
    {
        $analyzer = $this->createAnalyzer();
        $artifact = $this->safeArtifact();

        $r1 = $analyzer->analyze($artifact, null);
        $r2 = $analyzer->analyze($artifact, null);

        $this->assertSame($r1->toArray(), $r2->toArray(), 'analyze() must be pure (same input → same output).');
    }

    final public function test_clean_migration_produces_no_errors(): void
    {
        $analyzer = $this->createAnalyzer();
        $result = $analyzer->analyze($this->safeArtifact(), null);

        $this->assertFalse($result->hasErrors(), 'A clean migration must produce no errors.');
    }

    final public function test_engine_returns_non_empty_string(): void
    {
        $analyzer = $this->createAnalyzer();
        $this->assertNotSame('', $analyzer->engine());
    }

    final public function test_capability_descriptor_is_well_formed(): void
    {
        $descriptor = $this->createAnalyzer()->capabilities();
        $this->assertIsArray($descriptor->toArray()['capabilities']);
    }

    private function safeArtifact(): MigrationArtifact
    {
        return new MigrationArtifact(
            version: 'SafeMigration',
            className: null,
            phase: MigrationPhase::Expand,
            upSql: ['ALTER TABLE users ADD COLUMN nickname VARCHAR(100) NULL'],
            downSql: ['ALTER TABLE users DROP COLUMN nickname'],
            hasAllowFullTableRewrite: false,
        );
    }
}
