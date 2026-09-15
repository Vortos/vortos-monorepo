<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\DatabaseRolesCheck;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Tests\Access\RoleModelFixture;
use Vortos\OpsKit\Gate\GateDisposition;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

final class DatabaseRolesCheckTest extends TestCase
{
    use PreflightTestFactory;

    private function check(array $snapshot, ?array $declaration): DatabaseRolesCheck
    {
        return new DatabaseRolesCheck(new DatabaseRoleConformanceInspector(
            RoleModelFixture::reader($snapshot),
            RoleModelFixture::tables(),
            new DeclaredDatabaseRoles($declaration),
        ));
    }

    public function test_it_reports_violations_by_name_and_never_blocks(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['roles']['app_runtime']['superuser'] = true;

        $check = $this->check($snapshot, RoleModelFixture::model()->toArray());
        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('role_attribute app_runtime runtime role: is SUPERUSER, must be NOSUPERUSER', $finding->detail);
        self::assertSame(GateDisposition::Advisory, $check->disposition());
        self::assertSame(PreflightCategory::Security, $check->category());
        self::assertSame('persistence.database_roles', $check->id());
    }

    public function test_conforming_passes_undeclared_skips_and_unreadable_fails(): void
    {
        $elsewhere = RoleModelFixture::conforming();
        $elsewhere['currentDatabase'] = 'postgres';

        self::assertSame(PreflightStatus::Pass, $this->check(RoleModelFixture::conforming(), RoleModelFixture::model()->toArray())->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, $this->check([], null)->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, (new DatabaseRolesCheck(null))->check($this->context())->status);
        self::assertSame(PreflightStatus::Fail, $this->check($elsewhere, RoleModelFixture::model()->toArray())->check($this->context())->status);
    }
}
