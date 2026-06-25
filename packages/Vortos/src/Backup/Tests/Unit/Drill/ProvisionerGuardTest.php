<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Drill\Driver\Postgres\EphemeralDatabaseProvisioner;

final class ProvisionerGuardTest extends TestCase
{
    public function test_rejects_production_looking_dsn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/production/i');

        new EphemeralDatabaseProvisioner('pgsql://user:pass@production-db:5432/mydb');
    }

    public function test_rejects_prod_db_dsn(): void
    {
        $this->expectException(\RuntimeException::class);

        new EphemeralDatabaseProvisioner('pgsql://user:pass@prod-db:5432/mydb');
    }

    public function test_accepts_non_prod_dsn(): void
    {
        // Should not throw — just verify construction succeeds
        $provisioner = new EphemeralDatabaseProvisioner('pgsql://user:pass@localhost:5432/test_drill');
        $this->assertNotNull($provisioner);
    }
}
