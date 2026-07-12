<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;

/**
 * Fixture with SQL in both up() and down() — used to prove extractFromClass()
 * returns only the up() statements, not the rollback DROPs.
 */
#[DeployPhase(MigrationPhase::Expand)]
final class FakeUpDownMigration
{
    public function up(): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_a ON t (a)');
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_b ON t (b)');
    }

    public function down(): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_b');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_a');
    }

    private function addSql(string $sql): void
    {
        // no-op; present only so the fixture is self-consistent PHP
    }
}
